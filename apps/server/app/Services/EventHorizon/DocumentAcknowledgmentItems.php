<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Domain\EventHorizon\EventHorizonCatalog;
use App\Domain\EventHorizon\EventHorizonItem;
use App\Domain\EventHorizon\EventHorizonItemKindDefinition;
use App\Domain\EventHorizon\EventHorizonItemState;
use App\Models\Department;
use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\Event;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Outstanding document acknowledgments (M18.39; HORIZON-003; POL-043 through
 * POL-047).
 *
 * One item per active acknowledgment requirement in the member's scope — the
 * event organization's own requirements, plus those of departments the member
 * actively belongs to — resolved against the M18.6 acknowledgment path, which
 * is the surface the action link opens.
 *
 * Two of that path's rules are restated rather than re-decided:
 *
 *  - a requirement pointing at an unpublished document is absent, matching
 *    `GET /api/document-acknowledgments/me`: the acknowledge command refuses
 *    one, and listing an item nobody can act on lists a fault as a task;
 *  - an acknowledgment satisfied at an earlier document version stays complete
 *    (POL-045). A new version re-requires nothing, and an item that read
 *    outstanding again after a revision would re-require it in the only way
 *    that matters to the person reading.
 *
 * Items here carry no deadline: POL-026 and POL-027 keep acknowledgment out of
 * shift signup and credential eligibility, so there is no moment it is due by
 * — it is something to read, not something blocking the reader from working.
 */
final class DocumentAcknowledgmentItems extends EventHorizonItemKind
{
    public function definition(): EventHorizonItemKindDefinition
    {
        return EventHorizonCatalog::definitions()[0];
    }

    /**
     * Acknowledgments are the viewer's own records, readable by any staff
     * member of the organization (POL-024), so standing is the whole gate.
     */
    public function availableTo(EventHorizonViewer $viewer, Event $event): bool
    {
        return $viewer->isEventStaff();
    }

    /**
     * @return list<EventHorizonItem>
     */
    public function compile(EventHorizonViewer $viewer, Event $event, Carbon $now): array
    {
        $departmentIds = $viewer->departmentIds();

        $requirements = DocumentAcknowledgmentRequirement::query()
            ->active()
            ->where('organization_id', $event->organization_id)
            ->where(function (Builder $query) use ($event, $departmentIds): void {
                $query
                    ->where(fn (Builder $scoped) => $scoped
                        ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION)
                        ->where('scope_id', $event->organization_id))
                    ->orWhere(fn (Builder $scoped) => $scoped
                        ->where('scope_type', DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT)
                        ->whereIn('scope_id', $departmentIds));
            })
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        $acknowledgments = DocumentAcknowledgment::query()
            ->where('user_id', $viewer->user->getKey())
            ->get();

        $items = [];

        foreach ($requirements as $requirement) {
            $document = $this->documentFor(
                (string) $requirement->document_type,
                (string) $requirement->document_id,
            );

            if ($document === null || ! $document->isPublished()) {
                continue;
            }

            $accepted = $acknowledgments->first(
                fn (DocumentAcknowledgment $acknowledgment): bool => $acknowledgment->document_type === $requirement->document_type
                    && (string) $acknowledgment->document_id === (string) $requirement->document_id
                    && $acknowledgment->scope_type === $requirement->scope_type
                    && (string) $acknowledgment->scope_id === (string) $requirement->scope_id,
            );

            $scopeName = $requirement->scope_type === DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT
                ? (string) (Department::query()->find($requirement->scope_id)?->name ?? 'your department')
                : 'the organization';

            $items[] = new EventHorizonItem(
                kind: $this->definition()->id,
                identity: 'document-acknowledgment:'.(string) $requirement->getKey(),
                state: $accepted === null
                    ? EventHorizonItemState::Outstanding
                    : EventHorizonItemState::Complete,
                title: (string) $document->title,
                evaluation: $accepted === null
                    ? sprintf('%s asks you to acknowledge this document and you have not yet.', ucfirst($scopeName))
                    : sprintf('You acknowledged this document, and the version you accepted is on record.'),
                completion: $accepted === null
                    ? 'Read the document and record your acknowledgment.'
                    : 'Nothing — this is done.',
                dueAt: null,
                actionSurface: 'staff.document-acknowledgments',
                actionLabel: 'Open your acknowledgments',
            );
        }

        return $items;
    }

    private function documentFor(string $type, string $id): PolicyDocument|ProcedureDocument|null
    {
        return $type === 'procedure'
            ? ProcedureDocument::query()->find($id)
            : PolicyDocument::query()->find($id);
    }
}
