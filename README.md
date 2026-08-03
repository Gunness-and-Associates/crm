# crmga CRM

Laravel 11 + Filament 3 CRM for **Gunness & Associates** — a metadata-driven rebuild of the legacy
SuiteCRM. Built and launched single-tenant, converted to multi-tenant (database-per-tenant) in the final
phase.

## Requirements
- PHP **8.3** (8.2 supported), Composer 2
- MySQL 8 / MariaDB (utf8mb4)
- Node 18+ (asset builds)
- Redis (queues / Horizon) — optional for local dev

## Setup
```bash
composer install
cp .env.example .env
php artisan key:generate
# set your DB credentials in .env, then:
php artisan migrate
```

## Run
```bash
php artisan serve   # http://127.0.0.1:8000/admin
```

## Quality gates (run before every commit)
```bash
vendor/bin/pint            # code style
vendor/bin/phpstan analyse # static analysis (level max)
vendor/bin/pest            # tests
```
CI runs all three on every pull request against PHP 8.2 and 8.3.

## Windows / XAMPP notes
- Use PHP **8.2+** (e.g. XAMPP's `php`), not an older PHP that may be first on `PATH`.
- XAMPP ships MariaDB, which lacks MySQL 8's default collation — set `DB_COLLATION=utf8mb4_unicode_ci`.
- Horizon needs `ext-pcntl`/`ext-posix` (Linux only); a `config.platform` override in `composer.json`
  lets `composer install` run on Windows. Run queues with `php artisan queue:work` locally instead of Horizon.

## Multi-tenancy — deferred to the final phase, prepared from day one
`stancl/tenancy` is **not** installed in v1. The app is single-tenant now and is converted to
database-per-tenant SaaS in the final phase, when the current database is promoted to tenant #1 with no
data movement. That is only cheap if these tenancy-ready rules hold from day one — a Pest guard
(`tests/Feature/TenancyReadyTest.php`) fails CI on a violation:

1. **All CRM migrations live in `database/migrations/tenant/`.** The default `database/migrations/`
   folder holds only central infrastructure (cache, jobs). Users, roles/permissions and settings are
   tenant-scoped, so they live under `tenant/`.
2. **Never add a `tenant_id` column.** Isolation is by database, not by row.
3. **Per-company configuration lives in the `settings` table**, never in `.env` — accessed via
   `App\Support\Settings` (`app(Settings::class)->get('key')`).
4. **`routes/central.php` stays an empty stub** until the final phase.

Also: file access only through the `Storage` facade; all cache/queue keys go through `App\Support\Keys`
so a tenant prefix can be injected in one place later.
