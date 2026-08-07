<?php

namespace App\Support\Livewire;

use Illuminate\Routing\Route;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire's stock getUpdateUri() builds an app-root-relative URL
 * (absolute: false), which drops the /newcrmga/public base path when the
 * app is served from a sub-directory instead of a vhost root. Returning an
 * absolute URL here fixes the update endpoint the same way for any
 * sub-directory deployment.
 */
class SubdirectoryHandleRequests extends HandleRequests
{
    public function getUpdateUri(): string
    {
        $route = $this->updateRoute ?? $this->findUpdateRoute();

        if (! $route instanceof Route) {
            // HandleRequests::boot() always registers the default update route unless a
            // custom one already exists, so this is unreachable in practice.
            throw new \RuntimeException('No Livewire update route is registered.');
        }

        return (string) app('url')->toRoute($route, [], true);
    }
}
