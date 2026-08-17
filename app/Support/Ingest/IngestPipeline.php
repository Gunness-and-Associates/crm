<?php

namespace App\Support\Ingest;

use App\Events\LeadIngested;
use App\Models\IngestLog;
use App\Models\Lead;
use App\Models\Metadata\OptionList;
use App\Support\Api\ApiModuleRegistry;
use App\Support\Api\ApiValidationRuleBuilder;
use App\Support\FullName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * The one pipeline every inbound source runs through (api-contract.md Part 3):
 * map -> canonicalise -> validate -> dedupe -> create-or-update -> assign ->
 * fire events -> log. Reuses the same ApiValidationRuleBuilder/ApiModuleRegistry
 * v1 and the legacy adapter already use — no separate validation logic here.
 */
final class IngestPipeline
{
    public function __construct(
        private readonly FieldMapRepository $fieldMaps,
        private readonly ApiModuleRegistry $registry,
        private readonly ApiValidationRuleBuilder $validationRules,
        private readonly Deduplicator $deduplicator,
        private readonly LeadAssigner $assigner,
    ) {}

    /**
     * @param  array<string, mixed>  $rawPayload  already flattened to a flat key => value array
     */
    public function run(string $source, array $rawPayload, string $moduleKey = 'leads'): IngestResult
    {
        $log = IngestLog::create(['source' => $source, 'raw_payload' => $rawPayload, 'status' => 'received']);

        try {
            $modelClass = $this->registry->modelFor($moduleKey);
            $canonicalFields = $this->registry->fields($moduleKey);

            $mapped = $this->mapFields($source, $rawPayload);
            $canonicalised = $this->canonicalise($source, $mapped, $canonicalFields);
            $rules = $this->validationRules->build($canonicalFields, forCreate: true);
            $validated = $this->stringKeyed(Validator::make($canonicalised, $rules)->validate());

            $email = is_string($validated['primary_email'] ?? null) ? $validated['primary_email'] : null;
            $phone = is_string($validated['phone_mobile'] ?? null) ? $validated['phone_mobile'] : null;
            $match = $this->deduplicator->find($modelClass, $email, $phone);

            [$record, $status] = $this->createOrMerge($modelClass, $moduleKey, $validated, $match);

            $log->update([
                'mapped_attributes' => $validated,
                'status' => $status,
                'record_id' => $record->getKey(),
                'matched_by' => $match?->matchedBy,
            ]);

            if ($record instanceof Lead) {
                LeadIngested::dispatch($record, $source, $match?->matchedBy);
            }

            return new IngestResult($record, $status, $match?->matchedBy);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $validated
     * @return array{0: Model, 1: string}
     */
    private function createOrMerge(string $modelClass, string $moduleKey, array $validated, ?DedupeMatch $match): array
    {
        if ($match !== null && $this->deduplicator->modeFor($moduleKey) === 'merge') {
            $match->record->fill($validated)->save();

            return [$match->record, 'processed'];
        }

        /** @var Model $record */
        $record = $modelClass::create($validated);
        $this->assigner->assign($record);

        return [$record, $match !== null ? 'duplicate' : 'processed'];
    }

    /**
     * Multiple rules commonly target the same field (e.g. both `your-name` and
     * `name` -> `first_name`, so either a Contact Form 7 or a Gravity Forms
     * payload works with the same map) — the first rule whose own source key is
     * actually present wins; a later rule whose source key is absent must not
     * overwrite that real value with its own null/default.
     *
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, mixed>
     */
    private function mapFields(string $source, array $rawPayload): array
    {
        $result = [];
        foreach ($this->fieldMaps->forSource($source) as $mapping) {
            if (array_key_exists($mapping->targetField, $result) && $result[$mapping->targetField] !== null) {
                continue;
            }

            if (! array_key_exists($mapping->sourceField, $rawPayload) && $mapping->default === null) {
                continue;
            }

            $value = $rawPayload[$mapping->sourceField] ?? $mapping->default;
            $result[$mapping->targetField] = $this->applyTransform($mapping->transform, $value);
        }

        return $result;
    }

    private function applyTransform(?string $transform, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($transform) {
            'trim' => trim($value),
            'lower' => strtolower($value),
            'upper' => strtoupper($value),
            'phone' => PhoneCleaner::clean($value),
            'name_first' => FullName::split($value)[0],
            'name_last' => FullName::split($value)[1],
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, array<string, mixed>>  $canonicalFields
     * @return array<string, mixed>
     */
    private function canonicalise(string $source, array $mapped, array $canonicalFields): array
    {
        foreach ($mapped as $field => $value) {
            $type = $canonicalFields[$field]['type'] ?? null;
            if ($type !== 'enum' && $type !== 'multienum') {
                continue;
            }

            $optionListId = $canonicalFields[$field]['option_list_id'] ?? null;
            if (! is_string($optionListId)) {
                continue;
            }

            $list = OptionList::query()->with('items')->find($optionListId);
            if ($list === null) {
                continue;
            }

            $mapped[$field] = $type === 'multienum'
                ? array_values(array_filter(array_map(
                    fn (mixed $item): ?string => Canon::matchOption(is_string($item) ? $item : null, $list, $source, $field),
                    is_array($value) ? $value : [$value],
                )))
                : Canon::matchOption(is_string($value) ? $value : null, $list, $source, $field);
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
