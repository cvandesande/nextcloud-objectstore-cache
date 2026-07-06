<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CVan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ObjectStoreCache;

/**
 * Read-only stream that yields an in-memory prefix followed by the remainder of an
 * underlying stream.
 *
 * It lets a large object be handed back after its first bytes were already read to
 * decide whether it fits the cache, without rewinding the original stream. Rewinding
 * the backend's httpseek:// stream would re-fetch the whole object with a second HTTP
 * request; reusing the bytes already read avoids that. Seeks are delegated to the
 * underlying (seekable) stream, so range requests keep working.
 *
 * The underlying stream must be positioned immediately after the prefix, so the two
 * share one offset space: bytes [0, strlen(prefix)) come from the prefix and the rest
 * from the underlying stream, whose position equals the overall read offset.
 */
class PrefixStream {
	private const PROTOCOL = 'objectstorecache.prefix';

	/** @var resource populated by PHP when the stream is opened */
	public $context;

	private string $prefix = '';
	private int $prefixOffset = 0;

	/** @var resource */
	private $stream;

	/**
	 * Wrap a prefix and an already partially read stream in a single read stream.
	 *
	 * @param resource $stream positioned immediately after $prefix
	 * @return resource|false
	 */
	public static function open(string $prefix, $stream) {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		$context = stream_context_create([
			self::PROTOCOL => ['prefix' => $prefix, 'stream' => $stream],
		]);
		return fopen(self::PROTOCOL . '://', 'r', false, $context);
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$context = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;
		if (!is_array($context) || !is_string($context['prefix'] ?? null) || !is_resource($context['stream'] ?? null)) {
			return false;
		}
		$this->prefix = $context['prefix'];
		$this->stream = $context['stream'];
		return true;
	}

	/**
	 * @return string|false
	 */
	public function stream_read(int $count) {
		$data = '';
		if ($this->prefixOffset < strlen($this->prefix)) {
			$data = substr($this->prefix, $this->prefixOffset, $count);
			$this->prefixOffset += strlen($data);
			$count -= strlen($data);
			if ($count <= 0) {
				return $data;
			}
		}
		$rest = fread($this->stream, $count);
		return $rest === false ? $data : $data . $rest;
	}

	public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
		// Resolve everything to an absolute offset against the underlying stream, which
		// shares the overall offset space, then let it seek (re-fetching if needed).
		if ($whence === SEEK_CUR) {
			$offset += $this->stream_tell();
			$whence = SEEK_SET;
		}
		if (fseek($this->stream, $offset, $whence) !== 0) {
			return false;
		}
		$this->prefixOffset = strlen($this->prefix);
		return true;
	}

	public function stream_tell(): int {
		if ($this->prefixOffset < strlen($this->prefix)) {
			return $this->prefixOffset;
		}
		$position = ftell($this->stream);
		return $position === false ? strlen($this->prefix) : $position;
	}

	public function stream_eof(): bool {
		if ($this->prefixOffset < strlen($this->prefix)) {
			return false;
		}
		return feof($this->stream);
	}

	/**
	 * @return array<int|string, int>|false
	 */
	public function stream_stat() {
		return fstat($this->stream);
	}

	public function stream_close(): void {
		$stream = $this->stream;
		if (is_resource($stream)) {
			fclose($stream);
		}
	}
}
