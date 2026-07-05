<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CVan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ObjectStoreCache\Tests\Unit;

use OCA\ObjectStoreCache\CachingObjectStore;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\Files\ObjectStore\IObjectStoreMetaData;
use OCP\Files\ObjectStore\IObjectStoreMultiPartUpload;
use OCP\ICache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A backend that satisfies every object-store interface at once, so a single
 * mock can stand in for the wrapped store.
 */
interface FullBackend extends IObjectStore, IObjectStoreMetaData, IObjectStoreMultiPartUpload {
}

class CachingObjectStoreTest extends TestCase {
	private FullBackend&MockObject $backend;
	private ICache&MockObject $cache;

	protected function setUp(): void {
		parent::setUp();
		$this->backend = $this->createMock(FullBackend::class);
		$this->cache = $this->createMock(ICache::class);
	}

	/**
	 * Build the wrapper with its backend, cache and logger seams overridden, so the
	 * tests drive it against the mocks above without touching a real object store.
	 */
	private function store(?ICache $cache = null): CachingObjectStore&MockObject {
		$store = $this->getMockBuilder(CachingObjectStore::class)
			->setConstructorArgs([[]])
			->onlyMethods(['getBackend', 'getMetaDataBackend', 'getMultiPartBackend', 'getCache', 'getLogger'])
			->getMock();
		$store->method('getBackend')->willReturn($this->backend);
		$store->method('getMetaDataBackend')->willReturn($this->backend);
		$store->method('getMultiPartBackend')->willReturn($this->backend);
		$store->method('getCache')->willReturn($cache);
		$store->method('getLogger')->willReturn(new NullLogger());
		return $store;
	}

	/**
	 * @return resource
	 */
	private function streamOf(string $content) {
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}

	/**
	 * A cache entry as stored by the wrapper: base64 content plus a freshness
	 * deadline the given number of seconds in the future (negative for a stale one).
	 *
	 * @return array{expires: int, data: string}
	 */
	private function entry(string $content, int $freshFor): array {
		return ['expires' => time() + $freshFor, 'data' => base64_encode($content)];
	}

	public function testReadWithoutCacheHitsBackend(): void {
		$this->backend->expects($this->once())
			->method('readObject')
			->with('urn:1')
			->willReturn($this->streamOf('body'));

		$store = $this->store(null);
		self::assertSame('body', stream_get_contents($store->readObject('urn:1')));
	}

	public function testReadMissPopulatesCache(): void {
		$this->cache->method('get')->with('urn:1')->willReturn(null);
		$this->backend->expects($this->once())
			->method('readObject')
			->willReturn($this->streamOf('body'));
		$this->cache->expects($this->once())
			->method('set')
			->with(
				'urn:1',
				$this->callback(fn ($v) => is_array($v) && isset($v['data'], $v['expires'])
					&& base64_decode($v['data'], true) === 'body' && $v['expires'] > time()),
				$this->anything(),
			);

		$store = $this->store($this->cache);
		self::assertSame('body', stream_get_contents($store->readObject('urn:1')));
	}

	public function testFreshHitSkipsBackend(): void {
		$this->cache->method('get')->with('urn:1')->willReturn($this->entry('cached', 100));
		$this->backend->expects($this->never())->method('readObject');

		$store = $this->store($this->cache);
		self::assertSame('cached', stream_get_contents($store->readObject('urn:1')));
	}

	public function testStaleEntryIsRefreshedWhenBackendHealthy(): void {
		$this->cache->method('get')->with('urn:1')->willReturn($this->entry('old', -10));
		$this->backend->expects($this->once())
			->method('readObject')
			->willReturn($this->streamOf('new'));
		$this->cache->expects($this->once())->method('set');

		$store = $this->store($this->cache);
		self::assertSame('new', stream_get_contents($store->readObject('urn:1')));
	}

	public function testStaleEntryIsServedWhenBackendFails(): void {
		$this->cache->method('get')->with('urn:1')->willReturn($this->entry('last-good', -10));
		$this->backend->method('readObject')->willThrowException(new \RuntimeException('backend down'));
		// A definite "does not exist" makes the read give up at once, without backoff.
		$this->backend->method('objectExists')->willReturn(false);

		$store = $this->store($this->cache);
		self::assertSame('last-good', stream_get_contents($store->readObject('urn:1')));
	}

	public function testReadFailsWhenBackendFailsAndNothingCached(): void {
		$this->cache->method('get')->with('urn:1')->willReturn(null);
		$this->backend->method('readObject')->willThrowException(new \RuntimeException('backend down'));
		$this->backend->method('objectExists')->willReturn(false);

		$store = $this->store($this->cache);
		$this->expectException(\Throwable::class);
		$store->readObject('urn:1');
	}

	public function testLargeObjectIsNotCached(): void {
		$content = str_repeat('x', 70000);
		$this->cache->method('get')->willReturn(null);
		$this->backend->method('readObject')->willReturn($this->streamOf($content));
		$this->cache->expects($this->never())->method('set');

		$store = $this->store($this->cache);
		self::assertSame($content, stream_get_contents($store->readObject('urn:big')));
	}

	public function testGrownObjectDropsStaleSmallEntry(): void {
		// A small entry is cached but the object has since grown beyond the cache limit.
		$this->cache->method('get')->willReturn($this->entry('small', -10));
		$this->backend->method('readObject')->willReturn($this->streamOf(str_repeat('x', 70000)));
		$this->cache->expects($this->never())->method('set');
		$this->cache->expects($this->once())->method('remove')->with('urn:grown');

		$store = $this->store($this->cache);
		self::assertSame(70000, strlen(stream_get_contents($store->readObject('urn:grown'))));
	}

	public function testWriteInvalidatesCache(): void {
		$this->cache->expects($this->once())->method('remove')->with('urn:1');
		$this->backend->expects($this->once())->method('writeObject')->with('urn:1');

		$store = $this->store($this->cache);
		$store->writeObject('urn:1', $this->streamOf('body'), 'text/plain');
	}

	public function testDeleteInvalidatesCache(): void {
		$this->cache->expects($this->once())->method('remove')->with('urn:1');
		$this->backend->expects($this->once())->method('deleteObject')->with('urn:1');

		$store = $this->store($this->cache);
		$store->deleteObject('urn:1');
	}

	public function testCopyInvalidatesDestination(): void {
		$this->cache->expects($this->once())->method('remove')->with('urn:to');
		$this->backend->expects($this->once())->method('copyObject')->with('urn:from', 'urn:to');

		$store = $this->store($this->cache);
		$store->copyObject('urn:from', 'urn:to');
	}

	public function testTransientFailureIsRetried(): void {
		$attempts = 0;
		$this->backend->method('readObject')
			->willReturnCallback(function () use (&$attempts) {
				$attempts++;
				if ($attempts < 3) {
					throw new \RuntimeException('503 Slow Down');
				}
				return $this->streamOf('recovered');
			});
		$this->backend->method('objectExists')->willReturn(true);

		$store = $this->store(null);
		self::assertSame('recovered', stream_get_contents($store->readObject('urn:retry')));
		self::assertSame(3, $attempts);
	}

	public function testMissingObjectFailsFast(): void {
		$reads = 0;
		$this->backend->method('readObject')
			->willReturnCallback(function () use (&$reads): void {
				$reads++;
				throw new \RuntimeException('not found');
			});
		$this->backend->expects($this->once())
			->method('objectExists')
			->with('urn:gone')
			->willReturn(false);

		$store = $this->store(null);
		try {
			$store->readObject('urn:gone');
			self::fail('expected the read to throw');
		} catch (\Throwable $e) {
			self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
			self::assertStringContainsString('failed after 1 attempt', $e->getMessage());
		}
		self::assertSame(1, $reads, 'a missing object must not exhaust the retry budget');
	}

	public function testUltimateFailureReportsHttpCauseWithoutLeakingSignature(): void {
		$this->backend->method('readObject')
			->willReturnCallback(function (): void {
				// Reads go through the httpseek:// wrapper: the inner HTTP request warns
				// with the status (and a signed URL), then the outer wrapper warns
				// generically. The inner cause must win, without leaking the signature.
				trigger_error(
					'fopen(https://host/bucket/urn:oid:1?X-Amz-Signature=deadbeefsecret): '
						. 'Failed to open stream: HTTP request failed! HTTP/1.1 503 Slow Down',
					E_USER_WARNING,
				);
				trigger_error(
					'fopen(httpseek://): Failed to open stream: '
						. '"OC\Files\Stream\SeekableHttpStream::stream_open" call failed',
					E_USER_WARNING,
				);
				throw new \RuntimeException('Failed to read object');
			});
		$this->backend->method('objectExists')->willReturn(false);

		$store = $this->store(null);
		try {
			$store->readObject('urn:oid:1');
			self::fail('expected the read to throw');
		} catch (\Throwable $e) {
			self::assertStringContainsString('HTTP/1.1 503 Slow Down', $e->getMessage());
			self::assertStringNotContainsString('X-Amz-Signature', $e->getMessage());
			self::assertStringNotContainsString('stream_open', $e->getMessage());
		}
	}

	public function testMetadataOperationsAreDelegated(): void {
		$this->backend->expects($this->once())
			->method('getObjectMetaData')
			->with('urn:1')
			->willReturn(['size' => 5]);

		$store = $this->store(null);
		self::assertSame(['size' => 5], $store->getObjectMetaData('urn:1'));
	}
}
