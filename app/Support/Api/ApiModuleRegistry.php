<?php

namespace App\Support\Api;

use App\Models\Affiliate;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use App\Support\MetadataRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps a module key (the `{module}` route segment) to the real Eloquent model
 * class for it — the one thing ModuleResourceController needs to be "one generic
 * controller driven by metadata, not a controller per module" (Z-5.2).
 *
 * Deliberately NOT a fully dynamic model built from `fields`/`modules` alone: the
 * 7 shipped entities carry real business logic (HasAcl, HasCustomFields,
 * HasActivities, HasEmailAddresses) that a generic table-only model would bypass —
 * and rule 11 requires the API and the interface to share the exact same query
 * scopes, never a parallel path. A Studio-created custom module with no PHP class
 * is a natural future extension of this map once one exists to test against.
 */
final class ApiModuleRegistry
{
    /** @var array<string, class-string<Model>> */
    private const MODEL_MAP = [
        'leads' => Lead::class,
        'companies' => Company::class,
        'assessments' => Assessment::class,
        'students' => Student::class,
        'clients' => Client::class,
        'affiliates' => Affiliate::class,
        'newsletter_subscribers' => NewsletterSubscriber::class,
    ];

    public function __construct(private readonly MetadataRepository $repository) {}

    public function exists(string $moduleKey): bool
    {
        return array_key_exists($moduleKey, self::MODEL_MAP);
    }

    /**
     * @return class-string<Model>
     */
    public function modelFor(string $moduleKey): string
    {
        if (! $this->exists($moduleKey)) {
            throw new \InvalidArgumentException("No API-registered model for module [{$moduleKey}].");
        }

        return self::MODEL_MAP[$moduleKey];
    }

    /**
     * The compiled metadata for one module's fields, keyed by field name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(string $moduleKey): array
    {
        $modules = $this->repository->compiled()['modules'] ?? [];
        if (! is_array($modules)) {
            return [];
        }

        $module = $modules[$moduleKey] ?? null;
        if (! is_array($module)) {
            return [];
        }

        $fields = $module['fields'] ?? [];
        if (! is_array($fields)) {
            return [];
        }

        $result = [];
        foreach ($fields as $name => $field) {
            if (is_string($name) && is_array($field)) {
                $result[$name] = $this->stringKeyedArray($field);
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed, mixed>  $array
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function filterableFields(string $moduleKey): array
    {
        return $this->fieldsWhere($moduleKey, 'filterable');
    }

    /**
     * @return list<string>
     */
    public function sortableFields(string $moduleKey): array
    {
        return $this->fieldsWhere($moduleKey, 'sortable');
    }

    /**
     * @return list<string>
     */
    private function fieldsWhere(string $moduleKey, string $flag): array
    {
        $names = [];
        foreach ($this->fields($moduleKey) as $name => $field) {
            if ((bool) ($field[$flag] ?? false)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
