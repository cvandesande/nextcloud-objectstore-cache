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
use Psr\Log\LoggerInterface;

/**
 * Primary object store that wraps another object store backend (the built-in S3
 * backend by default) with a read-through cache and transient-failure retries.
 *
 * Small objects (typically note bodies and the per-file encryption keys) are
 * cached in the distributed cache, so repeated reads are served without hitting
 * the backing store; and failed reads are retried with an exponential backoff so
 * a throttling backend (e.g. one answering "503 Slow Down") does not fail a read
 * on the first attempt. If a read still fails and a stale copy of the object is
 * cached, that copy is served rather than failing the read ("stale-if-error",
 * RFC 5861), so an object seen before, notably the encryption keys read on nearly
 * every request, stays readable through a backend outage.
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
	 * How long a cached object stays fresh, in seconds. While fresh it is served
	 * straight from the cache without contacting the backend. Invalidation on write,
	 * delete and copy keeps the cache correct in the common case, so the lifetime
	 * only bounds a missed invalidation or a read that repopulates just after a
	 * concurrent write invalidated, while staying well above typical client sync
	 * intervals so repeated reads keep hitting the cache.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Grace period, in seconds, during which a stale entry may still be served after
	 * it stops being fresh, but only when the backend fails to provide a fresh copy
	 * ("stale-if-error", RFC 5861). The entry lives in the cache for CACHE_TTL plus
	 * this grace period so it stays available to serve while stale. This keeps
	 * previously seen objects readable through a backend outage instead of surfacing
	 * a hard error, and is bounded so a removed object is not served indefinitely.
	 */
	private const STALE_IF_ERROR = 86400;

	/** Maximum number of attempts for a transient read failure. */
	private const READ_MAX_ATTEMPTS = 10;

	private ?IObjectStore $backend = null;
	private ?ICache $cache = null;
	private bool $cacheInitialised = false;
	private ?LoggerInterface $logger = null;

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
		if ($cache === null) {
			return $this->readWithRetry($urn);
		}

		$entry = $this->decodeCacheEntry($cache->get($urn));
		if ($entry !== null && $entry['expires'] > time()) {
			return $this->createMemoryStream($entry['content']);
		}

		// missing or stale: read through the backend, but fall back to a stale entry
		// if the backend fails (stale-if-error), so a flaky backend does not break
		// objects that have been read before
		try {
			$stream = $this->readWithRetry($urn);
		} catch (\Throwable $e) {
			if ($entry === null) {
				throw $e;
			}
			$this->getLogger()->warning('Serving stale cache entry for object ' . $urn . ' after the backend read failed', ['exception' => $e, 'app' => 'objectstore_cache']);
			return $this->createMemoryStream($entry['content']);
		}

		// cache small objects, stream large ones through. the size is not known up
		// front, so read up to the limit: a short read means the whole object fitted
		// and is cached
		$content = stream_get_contents($stream, self::CACHE_MAX_SIZE + 1);
		if ($content === false) {
			return $stream;
		}
		if (strlen($content) > self::CACHE_MAX_SIZE) {
			if ($entry !== null) {
				// object grew past the cache limit, drop the stale small entry so it
				// is never served in its place
				$cache->remove($urn);
			}
			// hand back the bytes already read followed by the rest of the stream,
			// rather than rewinding, which on the httpseek:// wrapper would re-fetch
			// the whole object from the backend
			$prefixed = PrefixStream::open($content, $stream);
			if ($prefixed !== false) {
				return $prefixed;
			}
			rewind($stream);
			return $stream;
		}
		fclose($stream);
		$this->storeCacheEntry($cache, $urn, $content);
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
	 * handled through retries. Its text carries the HTTP status, so the last one is
	 * kept and attached to the exception thrown when the retries are exhausted, to
	 * record why the read ultimately failed.
	 *
	 * @return resource
	 */
	private function readWithRetry(string $urn) {
		$lastError = null;
		set_error_handler(static function (int $errno, string $errstr) use (&$lastError): bool {
			if (!str_contains($errstr, 'Failed to open stream') && !str_contains($errstr, 'HTTP request failed')) {
				return false;
			}
			// Keep the first stream error of the attempt: reads go through the
			// httpseek:// wrapper, whose inner HTTP request fails (carrying the status)
			// before the outer wrapper reports a generic failure.
			$lastError ??= $errstr;
			return true;
		});
		try {
			$backend = $this->getBackend();
			$attempt = 0;
			$existenceChecked = false;
			while (true) {
				$attempt++;
				$lastError = null;
				try {
					return $backend->readObject($urn);
				} catch (\Throwable $e) {
					if ($attempt >= self::READ_MAX_ATTEMPTS || (!$existenceChecked && !$this->objectMayExist($urn))) {
						throw $this->readException($urn, $attempt, $lastError, $e);
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
	 * Build the exception thrown when a read is given up on. Its message records the
	 * object, the number of attempts and the underlying HTTP cause (e.g.
	 * "HTTP/1.1 503 Slow Down"), and chains the backend's own exception, so the
	 * reason a request ultimately failed is visible in the log.
	 */
	private function readException(string $urn, int $attempts, ?string $lastError, \Throwable $previous): \Exception {
		$reason = $lastError !== null ? ': ' . $this->summariseHttpError($lastError) : '';
		return new \Exception('Reading object ' . $urn . ' failed after ' . $attempts . ' attempt(s)' . $reason, 0, $previous);
	}

	/**
	 * Reduce a PHP stream-wrapper warning to its meaningful cause. The HTTP status
	 * line is preferred; failing that, any presigned-URL query string is stripped so
	 * the request signature is never written to the log.
	 */
	private function summariseHttpError(string $error): string {
		if (preg_match('#HTTP/\S+ \d{3}[^\r\n"]*#', $error, $matches) === 1) {
			return trim($matches[0]);
		}
		return trim((string)preg_replace('/\?[^\s)]*/', '', $error));
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
	 * Decode a cache entry into its content and freshness deadline, or null when the
	 * entry is absent or unreadable (for instance one left over in an older format).
	 *
	 * @return array{content: string, expires: int}|null
	 */
	private function decodeCacheEntry(mixed $entry): ?array {
		if (!is_array($entry) || !isset($entry['expires']) || !isset($entry['data']) || !is_string($entry['data'])) {
			return null;
		}
		$content = base64_decode($entry['data'], true);
		if ($content === false) {
			return null;
		}
		return ['content' => $content, 'expires' => (int)$entry['expires']];
	}

	/**
	 * Store an object in the cache. The content is base64 encoded because the
	 * distributed cache serialises values as JSON, which cannot hold raw (encrypted)
	 * bytes. The entry is kept for the freshness lifetime plus the stale-if-error
	 * grace period, so it stays available to serve while stale.
	 */
	private function storeCacheEntry(ICache $cache, string $urn, string $content): void {
		$cache->set($urn, [
			'expires' => time() + self::CACHE_TTL,
			'data' => base64_encode($content),
		], self::CACHE_TTL + self::STALE_IF_ERROR);
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

	protected function getLogger(): LoggerInterface {
		if ($this->logger === null) {
			$this->logger = Server::get(LoggerInterface::class);
		}
		return $this->logger;
	}
}
