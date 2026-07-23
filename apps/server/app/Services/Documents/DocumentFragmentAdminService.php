<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\AuditEvent;
use App\Models\DocumentFragment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Persists fragment edits and their append-only audit metadata.
 */
class DocumentFragmentAdminService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentScopeValidator $scopes,
    ) {}

    /**
     * @param  array<string, string>  $attributes
     */
    public function save(
        DocumentFragment $fragment,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_ORCHID,
    ): DocumentFragment
    {
        return DB::transaction(function () use ($fragment, $attributes, $actor, $sourceContext): DocumentFragment {
            $isNew = ! $fragment->exists;
            $before = $isNew ? null : $this->snapshot($fragment);

            $fragment->fill($attributes);

            if ($isNew) {
                $fragment->created_by_user_id = $actor->id;
            }

            $fragment->updated_by_user_id = $actor->id;
            $fragment->save();

            $after = $this->snapshot($fragment->refresh());

            if ($isNew || $before !== $after) {
                $this->audit->recordForEntity(
                    entity: $fragment,
                    action: $isNew ? 'document_fragment.created' : 'document_fragment.updated',
                    actorUser: $actor,
                    organizationId: $fragment->organization_id,
                    departmentId: $this->scopes->departmentId($fragment->scope_type, $fragment->scope_id),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $fragment;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(DocumentFragment $fragment): array
    {
        return [
            'organization_id' => $fragment->organization_id,
            'scope_type' => $fragment->scope_type,
            'scope_id' => $fragment->scope_id,
            'name' => $fragment->name,
            'slug' => $fragment->slug,
            'markdown_source' => $fragment->markdown_source,
            'version' => $fragment->version,
        ];
    }
}
