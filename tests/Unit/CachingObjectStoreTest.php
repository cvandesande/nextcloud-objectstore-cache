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
	 * Build the wrapper with its backend and cache seams overridden, so the tests
	 * drive it against the mocks above without touching a real object store.
	 */
	private function store(?ICache $cache = null): CachingObjectStore&MockObject {
		$store = $this->getMockBuilder(CachingObjectStore::class)
			->setConstructorArgs([[]])
			->onlyMethods(['getBackend', 'getMetaDataBackend', 'getMultiPartBackend', 'getCache'])
			->getMock();
		$store->method('getBackend')->willReturn($this->backend);
		$store->method('getMetaDataBackend')->willReturn($this->backend);
		$store->method('getMultiPartBackend')->willReturn($this->backend);
		$store->method('getCache')->willReturn($cache);
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
			->with('urn:1', base64_encode('body'), $this->anything());

		$store = $this->store($this->cache);
		self::assertSame('body', stream_get_contents($store->readObject('urn:1')));
	}

	public function testReadHitSkipsBackend(): void {
		$this->cache->method('get')->with('urn:1')->willReturn(base64_encode('cached'));
		$this->backend->expects($this->never())->method('readObject');

		$store = $this->store($this->cache);
		self::assertSame('cached', stream_get_contents($store->readObject('urn:1')));
	}

	public function testLargeObjectIsNotCached(): void {
		$content = str_repeat('x', 70000);
		$this->cache->method('get')->willReturn(null);
		$this->backend->method('readObject')->willReturn($this->streamOf($content));
		$this->cache->expects($this->never())->method('set');

		$store = $this->store($this->cache);
		self::assertSame($content, stream_get_contents($store->readObject('urn:big')));
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
		$this->expectException(\RuntimeException::class);
		try {
			$store->readObject('urn:gone');
		} finally {
			self::assertSame(1, $reads, 'a missing object must not exhaust the retry budget');
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
