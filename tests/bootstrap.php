<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CVan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../vendor/autoload.php';

// The nextcloud/ocp package ships the OCP\ stubs for static analysis but leaves
// its own composer autoload empty (at runtime OCP is provided by the server), so
// register a PSR-4 autoloader for the stubs to make the unit tests self-contained.
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}
	$file = __DIR__ . '/../vendor/nextcloud/ocp/OCP/' . str_replace('\\', '/', substr($class, 4)) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
