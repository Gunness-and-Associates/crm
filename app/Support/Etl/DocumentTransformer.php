<?php

namespace App\Support\Etl;

use App\Models\Document;
use App\Models\Lead;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use App\Support\Etl\Concerns\ResolvesActivitySubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Only 56 documents exist in the audited source at all; 43 are resolvable
 * via `ga_usa_documents_1_c`. No physical files are migrated (out of scope —
 * this is metadata only), so `file_path` (a real, NOT NULL column) carries
 * the legacy `doc_url` when present, or an obviously-not-a-real-path
 * `legacy://` marker referencing the source id otherwise.
 */
final class DocumentTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;
    use ResolvesActivitySubjects;

    public function key(): string
    {
        return 'activities_documents';
    }

    public function modelClass(): string
    {
        return Document::class;
    }

    public function query(?string $fromId): Builder
    {
        $ids = array_keys($this->resolveActivitySubjects($this->specs(), 'documents'));

        $query = DB::connection('legacy')->table('documents')->whereIn('id', $ids);

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public function transform(array $row): ?array
    {
        $id = $this->stringValue($row['id'] ?? null);
        if ($id === '') {
            return null;
        }

        $subject = $this->resolveActivitySubjects($this->specs(), 'documents')[$id] ?? null;
        if ($subject === null) {
            return null;
        }

        $deletedAt = (bool) ($row['deleted'] ?? false)
            ? LegacyDate::parse($this->nullableString($row['date_modified'] ?? null))
            : null;

        return [
            'id' => $id,
            'deleted_at' => $deletedAt,
            'subject_type' => $subject['class'],
            'subject_id' => $subject['id'],
            'assigned_user_id' => $this->nullableUuid($row['assigned_user_id'] ?? null),
            'created_by' => $this->nullableUuid($row['created_by'] ?? null),
            'name' => $this->nullableString($row['document_name'] ?? null) ?? 'Document',
            'file_path' => $this->nullableString($row['doc_url'] ?? null) ?? "legacy://documents/{$id}",
            'category' => $this->nullableString($row['category_id'] ?? null),
            'status' => $this->nullableString($row['status_id'] ?? null) ?? 'active',
            'is_template' => $this->legacyBool($row['is_template'] ?? null),
        ];
    }

    /**
     * @return list<ActivitySourceSpec>
     */
    private function specs(): array
    {
        return [
            ActivitySourceSpec::viaJunction('ga_usa_documents_1_c', 'ga_usa', Lead::class),
        ];
    }
}
