# Object Store Read Cache

A drop-in wrapper for Nextcloud's built-in **S3 primary object storage** that adds a
**read-through cache** and **transient-failure retries**, without patching Nextcloud
core. It exists to make an unreliable / rate-limiting object store (e.g. Hetzner
Object Storage, which returns `503 Slow Down` under bursts) usable as primary storage.

## Why

Nextcloud reads every object over PHP's `http://` stream wrapper, which has **no
retry logic** — a single `503` fails the read. And there is **no read cache**, so
request-amplifying clients (the Notes app re-reads *every* note's content on each
sync; server-side encryption adds a per-file key read) fire ~100–200 object GETs per
request, tripping per-bucket rate limits.

This wrapper:

- **Retries** reads on transient failure (connection error / `429` / `5xx`) with
  exponential backoff + jitter; genuinely missing objects fail fast (one HEAD check).
  When a read is finally given up on, the exception records the underlying HTTP cause
  (e.g. `HTTP/1.1 503 Slow Down`) so the reason is visible in the log.
- **Caches** small objects (≤ 64 KB — note bodies, encryption keys) in the
  distributed cache (Redis), so repeated reads are served without hitting the store.
  Measured ~70× faster on a warm repeat Notes read.
- **Serves stale on error** (`stale-if-error`, RFC 5861): if a read fails but a cached
  copy is still within its grace window, that copy is served instead of failing the
  read — so an object seen before (notably the encryption keys read on nearly every
  request) stays readable through a backend outage.

Encryption is unaffected: the wrapper sits **below** the encryption layer, so it only
ever sees the encrypted-at-rest bytes the object store already holds (base64-encoded
in the cache, since the cache serialises as JSON). The instance `secret` that unlocks
encryption lives only in `config.php` — never in the cache.

## How it works

`CachingObjectStore` **wraps** a backing object store by composition: it implements
`IObjectStore`, `IObjectStoreMetaData` and `IObjectStoreMultiPartUpload`, and delegates
every call to the backend it builds from its constructor arguments (the core
`\OC\Files\ObjectStore\S3` by default; override with the `backend` argument). Only the
read and mutating paths add behaviour:

- `readObject` — serves from the cache when warm, otherwise reads through the backend
  with retries and caches the result if it is small enough. Objects too large to cache
  stream straight through, without the extra backend round-trip a rewind would cost.
- `writeObject`, `writeObjectWithMetaData`, `deleteObject`, `copyObject` and
  `completeMultipartUpload` — invalidate the affected cache key, then delegate.

Everything else is a straight pass-through to the backend. Because the wrapper depends
only on the public `IObjectStore*` interfaces (not on core internals), it keeps working
across Nextcloud upgrades as long as those interfaces are stable.

Cache invalidation is provably complete because `ObjectStoreStorage` mutates data
*only* through those `IObjectStore` methods.

## Install

1. **Deploy the code** to the Nextcloud server, under an apps directory. Use a
   dedicated `custom_apps/` if your deployment has one; otherwise the default `apps/`
   directory works and is preserved across `occ upgrade`:

   ```
   <nextcloud>/apps/objectstore_cache/          (or <nextcloud>/custom_apps/objectstore_cache/)
       appinfo/info.xml
       lib/AppInfo/Application.php
       lib/CachingObjectStore.php
   ```

   (The directory name must be `objectstore_cache` to match the app id / namespace.)

2. **Load the class early.** Copy `config/objectstore-cache.config.php` into
   Nextcloud's `config/` directory. It `require`s the class before storage init, so
   the wrapper works whether or not the app is enabled — and a disabled app can never
   break file access. It looks for the class under both `custom_apps/` and `apps/`; add
   your own path to the list in that file if you deploy it elsewhere.

3. **Point primary storage at the wrapper** — in `config.php`, change only the
   `class` of the existing `objectstore` block (leave `arguments` untouched):

   ```php
   'objectstore' => [
       'class' => '\\OCA\\ObjectStoreCache\\CachingObjectStore',   // was \OC\Files\ObjectStore\S3
       'arguments' => [ /* unchanged: bucket, key, secret, hostname, … */ ],
   ],
   ```

4. **Restart PHP-FPM** (or the pod). With `opcache.validate_timestamps=0`, running
   workers won't pick up new code until the FPM master restarts.

### Verify

```php
// occ / CLI: a warm repeat read should be served from cache and be much faster.
```
Check the distributed cache is populating (keys under `objectstore:…:read:`) and that
`nextcloud.log` `Failed to open stream` warnings drop after the working set warms.

## Rollback

Set `objectstore.class` back to `\OC\Files\ObjectStore\S3` and restart PHP-FPM. The
wrapper adds no persistent state (the cache is disposable and self-invalidating).

## Durability across Nextcloud upgrades

This is the whole point of the wrapper vs. patching core. `occ upgrade` overwrites
core files (so a patched `S3ObjectTrait.php` silently vanishes) but does **not** touch
`config.php` or a custom app directory (whether under `custom_apps/` or a custom app
placed in `apps/`). Because the wrapper depends only on the public `IObjectStore*`
interfaces and delegates everything to the backend:

- It never touches core internals, so upgrades that refactor the S3 backend's private
  code cannot break it.
- If one of the object-store interfaces gains a method or changes a signature
  incompatibly, PHP fails **loudly** at load (a clear error), rather than silently
  reverting to un-cached, un-retried behaviour. That converts a silent regression into
  an obvious, quick fix.

Bump `max-version` in `appinfo/info.xml` as you validate new Nextcloud releases.

## Tunables (`lib/CachingObjectStore.php`)

| Constant | Default | Meaning |
|---|---|---|
| `CACHE_MAX_SIZE` | `65536` | Max object size (bytes) to cache; larger objects stream through untouched. |
| `CACHE_TTL` | `300` | How long an entry stays fresh (s). Bounds staleness from a missed invalidation / read-write race; keep it above your client sync interval. |
| `STALE_IF_ERROR` | `86400` | Grace window (s) after an entry stops being fresh during which it may still be served, but only when the backend read fails. Bounds how long a removed object could be served if it were ever changed out-of-band. |
| `READ_MAX_ATTEMPTS` | `10` | Max attempts for a transient read failure. |

## Known trade-offs

- **Retry granularity is coarser** than an in-core patch: a failed read retries the
  whole `readObject` (re-opening the stream) rather than per byte-range. Fine for the
  small-object workload this targets.
- **Cold start / long idle**: the cache only populates on a *successful* read, so the
  first read of a cold object still hits the store (and can still be throttled). A
  paced cache-warm job (e.g. from cron) and/or raising the object store's per-bucket
  rate limit close that last gap; this wrapper does not.

## Development

The unit tests mock the backend and cache seams (`getBackend`, `getCache`), so they run
without a live Nextcloud, object store or Redis.

With a local PHP/Composer:

```
composer install
composer run lint        # php -l
composer run cs:check    # coding-standard (php-cs-fixer)
composer run psalm       # static analysis
composer run test:unit   # PHPUnit unit tests
```

Or, with only Docker (no host PHP needed), via the `Makefile`:

```
make install
make check               # lint + cs + psalm + test (what CI runs)
make test                # just PHPUnit, on the production PHP (8.5)
make cs-fix              # apply coding-standard fixes
```

### Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

- **PHPUnit** across PHP **8.1 – 8.5** (8.5 is the production runtime).
- **Lint, coding standard and Psalm** on PHP 8.3.

Static analysis is pinned to 8.3 only because Psalm 5.x does not run on PHP 8.4+; it
analyses against the app's declared PHP floor, so the target is unaffected. The
application code itself is tested on 8.5.
