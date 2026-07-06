<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CVan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ObjectStoreCache\Tests\Unit;

use OCA\ObjectStoreCache\PrefixStream;
use PHPUnit\Framework\TestCase;

class PrefixStreamTest extends TestCase {
	/**
	 * Wrap the content the way the object store does: read the first $prefixLength
	 * bytes off a seekable stream (the cache-size probe), then prepend them back.
	 *
	 * @return resource
	 */
	private function wrap(string $content, int $prefixLength) {
		$underlying = fopen('php://temp', 'r+');
		fwrite($underlying, $content);
		rewind($underlying);
		$prefix = (string)fread($underlying, $prefixLength);
		$stream = PrefixStream::open($prefix, $underlying);
		self::assertIsResource($stream);
		return $stream;
	}

	/**
	 * @param resource $stream
	 */
	private function readExactly($stream, int $length): string {
		$buffer = '';
		while (strlen($buffer) < $length && !feof($stream)) {
			$chunk = fread($stream, $length - strlen($buffer));
			if ($chunk === '' || $chunk === false) {
				break;
			}
			$buffer .= $chunk;
		}
		return $buffer;
	}

	public function testSequentialReadReturnsFullContent(): void {
		$content = str_repeat('abcdefgh', 10000); // 80000 bytes, spans the prefix boundary
		$stream = $this->wrap($content, 65537);
		self::assertSame($content, stream_get_contents($stream));
	}

	public function testChunkedReadReturnsFullContent(): void {
		$content = random_bytes(50000);
		$stream = $this->wrap($content, 20000);
		$out = '';
		while (!feof($stream)) {
			$out .= fread($stream, 4096);
		}
		self::assertSame($content, $out);
	}

	public function testRewindThenReadReturnsFullContent(): void {
		$content = str_repeat('x', 40000);
		$stream = $this->wrap($content, 20000);
		$this->readExactly($stream, 25000); // consume across the prefix boundary
		rewind($stream);
		self::assertSame($content, stream_get_contents($stream));
	}

	public function testSeekWithinPrefixAndBeyond(): void {
		$content = '';
		for ($i = 0; $i < 10000; $i++) {
			$content .= sprintf('%08d', $i); // 80000 bytes, position-encoded
		}
		$stream = $this->wrap($content, 30000);

		fseek($stream, 104); // inside the prefix region
		self::assertSame(substr($content, 104, 16), $this->readExactly($stream, 16));

		fseek($stream, 50000); // beyond the prefix region
		self::assertSame(substr($content, 50000, 16), $this->readExactly($stream, 16));
	}

	public function testTellTracksPositionAcrossBoundary(): void {
		$content = str_repeat('y', 40000);
		$stream = $this->wrap($content, 20000);
		self::assertSame(0, ftell($stream));
		$this->readExactly($stream, 15000); // within the prefix
		self::assertSame(15000, ftell($stream));
		$this->readExactly($stream, 15000); // across the boundary
		self::assertSame(30000, ftell($stream));
	}

	public function testEofAfterFullRead(): void {
		$content = str_repeat('z', 30000);
		$stream = $this->wrap($content, 20000);
		self::assertFalse(feof($stream));
		stream_get_contents($stream);
		self::assertTrue(feof($stream));
	}
}
