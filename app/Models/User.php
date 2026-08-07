<?php

namespace App\Models;

use App\Support\TwoFactorAuthentication;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property bool $is_admin
 * @property string $status
 * @property string|null $reports_to_id
 * @property string $locale
 * @property string $timezone
 * @property string|null $email_signature
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 */
class User extends Authenticatable implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_admin' => false,
        'status' => 'active',
        'locale' => 'en',
        'timezone' => 'UTC',
    ];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'is_admin',
        'status',
        'reports_to_id',
        'locale',
        'timezone',
        'email_signature',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @var list<string> never captured in an audit's before/after values */
    protected $auditExclude = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ----- User types (STUDIO_API_RBAC Part 3) -----

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /** @return HasMany<User, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    // ----- Two-factor authentication -----

    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Generate a fresh secret + recovery codes (unconfirmed until confirmTwoFactor()).
     *
     * @return list<string> the plaintext recovery codes, shown to the user once
     */
    public function enableTwoFactor(): array
    {
        $service = app(TwoFactorAuthentication::class);
        $codes = $service->generateRecoveryCodes();

        $this->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $codes;
    }

    public function confirmTwoFactor(string $code): bool
    {
        if (! $this->verifyTwoFactorCode($code)) {
            return false;
        }

        $this->forceFill(['two_factor_confirmed_at' => now()])->save();

        return true;
    }

    public function verifyTwoFactorCode(string $code): bool
    {
        return $this->two_factor_secret !== null
            && app(TwoFactorAuthentication::class)->verify($this->two_factor_secret, $code);
    }

    public function useRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes ?? [];

        if (! in_array($code, $codes, true)) {
            return false;
        }

        $this->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$code])),
        ])->save();

        return true;
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function twoFactorQrCodeUrl(): ?string
    {
        if ($this->two_factor_secret === null) {
            return null;
        }

        return app(TwoFactorAuthentication::class)->qrCodeUrl(
            config()->string('app.name'),
            $this->email,
            $this->two_factor_secret,
        );
    }
}
