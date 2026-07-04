<?php

/**
 * SPDX-FileCopyrightText: 2026 CVan
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Wiring for the Object Store Read Cache decorator.
 *
 * Drop this file into Nextcloud's `config/` directory (alongside config.php), e.g.
 * as `config/objectstore-cache.config.php`. Nextcloud loads every `config/*.config.php`
 * file very early — before primary object storage is initialised — so requiring the
 * class here guarantees it is available regardless of whether this is enabled as an
 * app. That decouples the decorator from app-enabled state: a disabled app can never
 * leave `objectstore.class` pointing at an unloadable class and break file access.
 *
 * This file only loads the class. Set the object-store class itself in the MAIN
 * config.php, so the S3 credentials/arguments stay in one place:
 *
 *     'objectstore' => [
 *         'class' => '\\OCA\\ObjectStoreCache\\CachingObjectStore',
 *         'arguments' => [ ...unchanged... ],
 *     ],
 *
 * The class is located relative to this file (which lives in Nextcloud's config/
 * directory), trying the standard app locations in turn: a dedicated `custom_apps/`
 * directory if present, otherwise the default `apps/` directory. Add your own path to
 * the list below if the app is deployed elsewhere.
 */

foreach ([
	__DIR__ . '/../custom_apps/objectstore_cache/lib/CachingObjectStore.php',
	__DIR__ . '/../apps/objectstore_cache/lib/CachingObjectStore.php',
] as $classFile) {
	if (is_file($classFile)) {
		require_once $classFile;
		break;
	}
}

$CONFIG = [];
