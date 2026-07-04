<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CVan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ObjectStoreCache;

use Aws\Result;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\Files\ObjectStore\IObjectStoreMetaData;
use OCP\Files\ObjectStore\IObjectStoreMultiPartUpload;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Server;

/**
 * Primary object store that wraps another object store backend (the built-in S3
 * backend by default) with a read-through cache and transient-failure retries.
 *
 * Small objects - typically note bodies and the per-file encryption keys - are
 * cached in the distributed cache, so repeated reads are served without hitting
 * the backing store; and failed reads are retried with an exponential backoff so
 * a throttling backend (e.g. one answering "503 Slow Down") does not fail a read
 * on the first attempt.
 *
 * It is wired in through config.php by replacing the object store class:
 *
 *     'objectstore' => [
 *         'class' => \OCA\ObjectStoreCache\CachingObjectStore::class,
 *         'arguments' => [ ...unchanged backend arguments... ],
 *     ],
 *
 * The wrapped backend defaults to the built-in S3 backend and can be overridden
 * with the 'backend' argument. Because the cache sits below the encryption layer
 * it only ever holds the encrypted-at-rest bytes the backend already stores.
 */
class CachingObjectStore implements IObjectStore, IObjectStoreMetaData, IObjectStoreMultiPartUpload {
	/** Default backing object store class name. */
	private const DEFAULT_BACKEND = 'OC\\Files\\ObjectStore\\S3';

	/** Objects up to this size (in bytes) are stored in the read cache. */
	private const CACHE_MAX_SIZE = 65536;

	/**
	 * Cache entry lifetime in seconds. Invalidation on write, delete and copy keeps
	 * the cache correct in the common case; the lifetime only bounds the two edge
	 * cases - a missed invalidation, or a read that repopulates just after a
	 * concurrent write invalidated - while staying well above typical client sync
	 * intervals so repeated reads keep hitting the cache.
	 */
	private const CACHE_TTL = 300;

	/** Maximum number of attempts for a transient read failure. */
	private const READ_MAX_ATTEMPTS = 10;

	private ?IObjectStore $backend = null;
	private ?ICache $cache = null;
	private bool $cacheInitialised = false;

	public function __construct(
		private array $parameters,
	) {
	}

	#[\Override]
	public function getStorageId() {
		return $this->getBackend()->getStorageId();
	}

	#[\Override]
	public function objectExists($urn) {
		return $this->getBackend()->objectExists($urn);
	}

	#[\Override]
	public function readObject($urn) {
		$cache = $this->getCache();
		if ($cache !== null) {
			$cached = $cache->get($urn);
			if (is_string($cached)) {
				$content = base64_decode($cached, true);
				if ($content !== false) {
					return $this->createMemoryStream($content);
				}
			}
		}

		$stream = $this->readWithRetry($urn);
		if ($cache === null) {
			return $stream;
		}

		// Cache small objects; stream large ones through untouched. The size is not
		// known up front, so read up to the limit: a short read means the whole
		// object fitted and is cached, otherwise the seekable stream is rewound and
		// returned as-is. Content is base64 encoded because the distributed cache
		// serialises values as JSON, which cannot hold raw (encrypted) bytes.
		$content = stream_get_contents($stream, self::CACHE_MAX_SIZE + 1);
		if ($content === false || strlen($content) > self::CACHE_MAX_SIZE) {
			rewind($stream);
			return $stream;
		}
		fclose($stream);
		$cache->set($urn, base64_encode($content), self::CACHE_TTL);
		return $this->createMemoryStream($content);
	}

	#[\Override]
	public function writeObject($urn, $stream, ?string $mimetype = null) {
		$this->invalidate($urn);
		$this->getBackend()->writeObject($urn, $stream, $mimetype);
	}

	#[\Override]
	public function writeObjectWithMetaData(string $urn, $stream, array $metaData): void {
		$this->invalidate($urn);
		$this->getMetaDataBackend()->writeObjectWithMetaData($urn, $stream, $metaData);
	}

	#[\Override]
	public function deleteObject($urn) {
		$this->invalidate($urn);
		$this->getBackend()->deleteObject($urn);
	}

	#[\Override]
	public function copyObject($from, $to) {
		$this->invalidate($to);
		$this->getBackend()->copyObject($from, $to);
	}

	#[\Override]
	public function preSignedUrl(string $urn, \DateTimeInterface $expiration): ?string {
		return $this->getBackend()->preSignedUrl($urn, $expiration);
	}

	#[\Override]
	public function getObjectMetaData(string $urn): array {
		return $this->getMetaDataBackend()->getObjectMetaData($urn);
	}

	#[\Override]
	public function listObjects(string $prefix = ''): \Iterator {
		return $this->getMetaDataBackend()->listObjects($prefix);
	}

	#[\Override]
	public function initiateMultipartUpload(string $urn): string {
		return $this->getMultiPartBackend()->initiateMultipartUpload($urn);
	}

	#[\Override]
	public function uploadMultipartPart(string $urn, string $uploadId, int $partId, $stream, $size): Result {
		return $this->getMultiPartBackend()->uploadMultipartPart($urn, $uploadId, $partId, $stream, $size);
	}

	#[\Override]
	public function getMultipartUploads(string $urn, string $uploadId): array {
		return $this->getMultiPartBackend()->getMultipartUploads($urn, $uploadId);
	}

	#[\Override]
	public function completeMultipartUpload(string $urn, string $uploadId, array $result): int {
		$this->invalidate($urn);
		return $this->getMultiPartBackend()->completeMultipartUpload($urn, $uploadId, $result);
	}

	#[\Override]
	public function abortMultipartUpload(string $urn, string $uploadId): void {
		$this->getMultiPartBackend()->abortMultipartUpload($urn, $uploadId);
	}

	/**
	 * Read from the backend, retrying transient failures with an exponential
	 * backoff. A genuinely missing object is not retried for the whole budget: on
	 * the first failure the object's existence is checked once, and a definite
	 * "does not exist" answer aborts the retries.
	 *
	 * The backend fetches over PHP's http wrapper, which emits a warning on each
	 * failed attempt; that specific warning is suppressed here because failures are
	 * handled through retries and, ultimately, a re-thrown exception.
	 *
	 * @return resource
	 */
	private function readWithRetry(string $urn) {
		set_error_handler(static function (int $errno, string $errstr): bool {
			return str_contains($errstr, 'Failed to open stream') || str_contains($errstr, 'HTTP request failed');
		});
		try {
			$backend = $this->getBackend();
			$attempt = 0;
			$existenceChecked = false;
			while (true) {
				$attempt++;
				try {
					return $backend->readObject($urn);
				} catch (\Throwable $e) {
					if ($attempt >= self::READ_MAX_ATTEMPTS || (!$existenceChecked && !$this->objectMayExist($urn))) {
						throw $e;
					}
					$existenceChecked = true;
					// exponential backoff with full jitter, capped at 2 seconds
					usleep(random_int(0, (int)min(2000000, 100000 * (2 ** ($attempt - 1)))));
				}
			}
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Whether the object might exist. Returns true when the backend cannot answer
	 * (e.g. it is unreachable), so an unavailable backend is treated as transient
	 * rather than as a missing object.
	 */
	private function objectMayExist(string $urn): bool {
		try {
			return $this->getBackend()->objectExists($urn);
		} catch (\Throwable) {
			return true;
		}
	}

	private function invalidate(string $urn): void {
		$this->getCache()?->remove($urn);
	}

	/**
	 * @return resource a seekable in-memory stream holding the given content
	 */
	private function createMemoryStream(string $content) {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	protected function getBackend(): IObjectStore {
		if ($this->backend === null) {
			/** @var class-string $class */
			$class = $this->parameters['backend'] ?? self::DEFAULT_BACKEND;
			$backend = new $class($this->parameters);
			if (!$backend instanceof IObjectStore) {
				throw new \LogicException('The configured object store backend ' . $class . ' does not implement ' . IObjectStore::class);
			}
			$this->backend = $backend;
		}
		return $this->backend;
	}

	protected function getMetaDataBackend(): IObjectStoreMetaData {
		$backend = $this->getBackend();
		if (!$backend instanceof IObjectStoreMetaData) {
			throw new \LogicException('The configured object store backend does not support metadata operations');
		}
		return $backend;
	}

	protected function getMultiPartBackend(): IObjectStoreMultiPartUpload {
		$backend = $this->getBackend();
		if (!$backend instanceof IObjectStoreMultiPartUpload) {
			throw new \LogicException('The configured object store backend does not support multipart uploads');
		}
		return $backend;
	}

	protected function getCache(): ?ICache {
		if (!$this->cacheInitialised) {
			$this->cacheInitialised = true;
			$factory = Server::get(ICacheFactory::class);
			if ($factory->isAvailable()) {
				$this->cache = $factory->createDistributed('objectstore:' . $this->getBackend()->getStorageId() . ':read:');
			}
		}
		return $this->cache;
	}
}
