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

## Multi-tenancy
Not installed in v1. Do **not** add `stancl/tenancy` or a `tenant_id` column before the final phase —
isolation will be database-per-tenant, and all CRM migrations live in `database/migrations/tenant/`.
