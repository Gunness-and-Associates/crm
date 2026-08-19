<?php

use App\Models\Affiliate;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

/**
 * BACKEND_BRIEF §7 -- "Audit: every create, update and delete of a CRM
 * record." Verifies every real CRM entity model actually implements
 * owen-it/laravel-auditing's Auditable, not just that the package is
 * installed -- a model missing the trait produces zero audit rows despite
 * the package being fully configured and working for every other model.
 */
dataset('crm_models', [
    'Lead' => [fn () => Lead::factory()->create(), fn (Model $m) => $m->update(['source' => 'changed'])],
    'Company' => [fn () => Company::factory()->create(), fn (Model $m) => $m->update(['industry' => 'changed'])],
    'Assessment' => [fn () => Assessment::factory()->create(), fn (Model $m) => $m->update(['status' => 'changed'])],
    'Student' => [fn () => Student::factory()->create(), fn (Model $m) => $m->update(['status' => 'changed'])],
    'Client' => [fn () => Client::factory()->create(), fn (Model $m) => $m->update(['client_status' => 'changed'])],
    'Affiliate' => [fn () => Affiliate::factory()->create(), fn (Model $m) => $m->update(['status' => 'changed'])],
    'NewsletterSubscriber' => [fn () => NewsletterSubscriber::factory()->create(), fn (Model $m) => $m->update(['status' => 'changed'])],
]);

it('audits create, update and delete for every CRM entity', function (Closure $create, Closure $update) {
    $model = $create();

    $update($model);
    $model->delete();

    $events = Audit::query()
        ->where('auditable_type', $model::class)
        ->where('auditable_id', $model->getKey())
        ->orderBy('created_at')
        ->pluck('event')
        ->all();

    expect($events)->toContain('created')
        ->toContain('updated')
        ->toContain('deleted');
})->with('crm_models');
