<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-company configuration store — tenancy-ready rule 3.
 *
 * SMTP, telephony, branding, business hours and enabled modules live here,
 * never in `.env`. Values are JSON-encoded in the `settings` table on the
 * current default connection (which becomes the tenant database at Phase 8)
 * and are cached as one compiled map. A value stored with $secret=true
 * (SMTP password, an integration API key) is additionally encrypted with the
 * app key before it ever reaches the database.
 */
final class Settings
{
    private const CACHE_KEY = 'settings:all';

    private const CACHE_TTL = 3600;

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        /** @var array<string, mixed> $values */
        $values = cache()->remember(Keys::cache(self::CACHE_KEY), self::CACHE_TTL, static function (): array {
            $out = [];

            foreach (Setting::all(['key', 'value', 'is_secret']) as $setting) {
                if ($setting->value === null) {
                    $out[$setting->key] = null;

                    continue;
                }

                $raw = $setting->is_secret ? Crypt::decryptString($setting->value) : $setting->value;
                $out[$setting->key] = json_decode($raw, true);
            }

            return $out;
        });

        return $this->loaded = $values;
    }

    public function set(string $key, mixed $value, bool $secret = false): void
    {
        $encoded = json_encode($value) ?: 'null';
        $stored = $secret ? Crypt::encryptString($encoded) : $encoded;

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $stored, 'is_secret' => $secret]);

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->flush();
    }

    public function flush(): void
    {
        $this->loaded = null;
        cache()->forget(Keys::cache(self::CACHE_KEY));
    }
}
