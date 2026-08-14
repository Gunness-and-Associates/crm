<?php

namespace App\Policies;

final class NewsletterSubscriberPolicy extends CrmPolicy
{
    protected function moduleKey(): string
    {
        return 'newsletter_subscribers';
    }
}
