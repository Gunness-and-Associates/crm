<?php

namespace App\Support\Etl;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Support\Etl\Concerns\NormalizesLegacyValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * document_revisions is not polymorphic — it belongs to Document directly,
 * so it only needs to know which documents actually migrated (DocumentTransformer),
 * not the subject-resolution machinery.
 */
final class DocumentRevisionTransformer implements LegacyTransformer
{
    use NormalizesLegacyValues;

    public function key(): string
    {
        return 'activities_document_revisions';
    }

    public function modelClass(): string
    {
        return DocumentRevision::class;
    }

    public function query(?string $fromId): Builder
    {
        $documentIds = Document::withoutGlobalScopes()->pluck('id')->all();

        $query = DB::connection('legacy')->table('document_revisions')->whereIn('document_id', $documentIds);

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
        $documentId = $this->stringValue($row['document_id'] ?? null);
        if ($id === '' || $documentId === '') {
            return null;
        }

        return [
            'id' => $id,
            'document_id' => $documentId,
            'revision_number' => $this->nullableInt($row['revision'] ?? null) ?? 1,
            'file_path' => $this->nullableString($row['doc_url'] ?? null) ?? "legacy://document-revisions/{$id}",
            'file_mime_type' => $this->nullableString($row['file_mime_type'] ?? null),
            'created_by' => $this->nullableUuid($row['created_by'] ?? null),
        ];
    }
}
