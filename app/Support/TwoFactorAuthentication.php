<?php

namespace App\Support;

use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Stateless TOTP helper (RFC 6238) built on google2fa. The per-user secret and
 * recovery codes are stored, encrypted, on the User model.
 */
final class TwoFactorAuthentication
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code) !== false;
    }

    /**
     * otpauth:// URL for an authenticator app QR code.
     */
    public function qrCodeUrl(string $issuer, string $account, string $secret): string
    {
        return $this->engine->getQRCodeUrl($issuer, $account, $secret);
    }

    /**
     * One-time recovery codes, shown to the user once and stored hashed-at-rest via encryption.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return array_map(
            static fn (): string => Str::random(10).'-'.Str::random(10),
            range(1, $count),
        );
    }
}
