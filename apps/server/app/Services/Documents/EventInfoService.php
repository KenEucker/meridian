<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Domain\Documents\EventInfoSection;
use App\Models\Event;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\User;

/**
 * Assembles the staff-facing Event Info screen from published documents
 * (M11.20).
 *
 * Assembly rules, in full:
 *
 * 1. Only `published` documents appear. Draft and archived documents never
 *    appear, not even for the maintainer who wrote them, because Event Info
 *    answers "what is in force right now" and a maintainer previewing their own
 *    draft here would read it as published guidance.
 * 2. Visibility is exactly the existing published-document rule. Event Info
 *    grants nothing: a team-scoped packing document reaches team members only,
 *    and a section can legitimately look different to two staff members.
 * 3. Documents are matched to the event through the event's organization, so
 *    an organization's documents are the pool and department/team scope narrows
 *    it further through the visibility rule above.
 * 4. Within a section, order is scope breadth (organization, department, team)
 *    then title. Broad guidance is read before the narrower guidance that
 *    qualifies it, and the order never shifts between two requests.
 * 5. A section with nothing visible renders as an explicit empty section rather
 *    than as prose. Placeholder text that reads like guidance is worse than a
 *    stated gap, because staff cannot tell it from the real thing.
 */
final class EventInfoService
{
    public function __construct(
        private readonly DocumentProductAccess $access,
        private readonly DocumentRenderer $renderer,
    ) {}

    /**
     * Event Info is a staff-safe surface, so event access is staff standing in
     * the event's organization rather than any operational capability.
     */
    public function canViewEventInfo(User $user, Event $event): bool
    {
        return $user->staffProfiles()
            ->whereHas('organizationStatuses', fn ($query) => $query
                ->where('organization_id', $event->organization_id))
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sectionsFor(User $user, Event $event): array
    {
        $visible = $this->visibleAssignedDocuments($user, $event);

        return array_map(
            fn (string $section): array => [
                'section' => $section,
                'label' => EventInfoSection::label($section),
                'documents' => array_map(
                    fn (PolicyDocument|ProcedureDocument $document): array => $this->documentPayload($document),
                    $visible[$section] ?? [],
                ),
                'empty_description' => ($visible[$section] ?? []) === []
                    ? EventInfoSection::emptyDescription($section)
                    : null,
            ],
            EventInfoSection::keys(),
        );
    }

    /**
     * @return array<string, list<PolicyDocument|ProcedureDocument>>
     */
    private function visibleAssignedDocuments(User $user, Event $event): array
    {
        $documents = [];

        foreach ([PolicyDocument::class, ProcedureDocument::class] as $model) {
            $assigned = $model::query()
                ->with(['organization', 'organizationScope', 'departmentScope', 'teamScope'])
                ->where('organization_id', $event->organization_id)
                ->where('state', $model::STATE_PUBLISHED)
                ->whereIn('event_info_section', EventInfoSection::keys())
                ->get();

            foreach ($assigned as $document) {
                if (! $this->access->canViewPublishedDocument($user, $document)) {
                    continue;
                }

                $documents[(string) $document->event_info_section][] = $document;
            }
        }

        foreach ($documents as $section => $sectionDocuments) {
            usort(
                $sectionDocuments,
                fn (PolicyDocument|ProcedureDocument $left, PolicyDocument|ProcedureDocument $right): int => [
                    EventInfoSection::scopeRank((string) $left->scope_type),
                    $left->title,
                    (string) $left->id,
                ] <=> [
                    EventInfoSection::scopeRank((string) $right->scope_type),
                    $right->title,
                    (string) $right->id,
                ],
            );

            $documents[$section] = $sectionDocuments;
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(PolicyDocument|ProcedureDocument $document): array
    {
        return [
            'id' => (string) $document->id,
            'document_type' => $document instanceof PolicyDocument ? 'policy' : 'procedure',
            'title' => $document->title,
            'slug' => $document->slug,
            'scope_type' => $document->scope_type,
            'scope_label' => $this->scopeLabel($document),
            'version' => $document->version(),
            'rendered_html' => $this->renderer->render($document),
            'published_at' => $document->published_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    private function scopeLabel(PolicyDocument|ProcedureDocument $document): string
    {
        return match ($document->scope_type) {
            PolicyDocument::SCOPE_ORGANIZATION => 'Organization: '.($document->organizationScope?->name ?? 'Not configured'),
            PolicyDocument::SCOPE_DEPARTMENT => 'Department: '.($document->departmentScope?->name ?? 'Not configured'),
            PolicyDocument::SCOPE_TEAM => 'Team: '.($document->teamScope?->name ?? 'Not configured'),
            default => 'Scope not configured',
        };
    }
}
