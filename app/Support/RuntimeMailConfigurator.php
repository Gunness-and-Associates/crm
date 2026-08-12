<?php

namespace App\Support;

/**
 * Builds the SMTP mailer from the settings store, never `.env` (BACKEND_BRIEF §2
 * rule 4 / tenancy-ready rule 3). A no-op until an administrator saves mail.host —
 * until then the app keeps whatever mailer config.php already resolved.
 */
final class RuntimeMailConfigurator
{
    public function __construct(private readonly Settings $settings) {}

    public function apply(): void
    {
        $host = $this->settings->get('mail.host');
        if (! is_string($host) || $host === '') {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $this->settings->get('mail.port', 587),
            'mail.mailers.smtp.encryption' => $this->settings->get('mail.encryption', 'tls'),
            'mail.mailers.smtp.username' => $this->settings->get('mail.username'),
            'mail.mailers.smtp.password' => $this->settings->get('mail.password'),
        ]);

        $fromAddress = $this->settings->get('mail.from_address');
        if (is_string($fromAddress) && $fromAddress !== '') {
            config([
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $this->settings->get('mail.from_name', config('mail.from.name')),
            ]);
        }
    }
}
