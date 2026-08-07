<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\DocumentAcknowledgment;
use App\Orchid\Layouts\Document\DocumentAcknowledgmentListLayout;
use App\Orchid\Layouts\ScopeFiltersLayout;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * Acknowledgment review in God Mode (M18.34; UI contract 12.9; technical spec
 * 22.2; POL-043 through POL-045).
 *
 * The record of who accepted which version of which document, when, and on
 * which node. It is the evidence half of the acknowledgment requirements: the
 * product surface at `organizer.documents` reads it for one organization under
 * `documents.acknowledgments.review`, and this reads it across the node behind
 * `platform.document-acknowledgments`.
 *
 * Spanning organizations is the whole difference, and it is safe here for the
 * reason the audit trail is: an acknowledgment is an attestation about a
 * document — a person, a title, a revision, a timestamp — and not the personal
 * or operational content that keeps the Field Report and incident screens
 * behind the product's own rules. Nothing in this row says anything about the
 * person beyond the fact that they read something they were asked to read.
 *
 * Read-only, and not by convention: {@see DocumentAcknowledgment} throws on
 * update and delete, because POL-045 depends on the record of an acceptance
 * outliving the version that was accepted.
 */
class DocumentAcknowledgmentListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'acknowledgments' => DocumentAcknowledgment::query()
                ->with(['user', 'staff', 'document', 'acceptedByNode', 'organizationScope', 'departmentScope'])
                ->filters($this->scopeFilters()->filters())
                ->filters()
                ->defaultSort('acknowledged_at', 'desc')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Document Acknowledgments';
    }

    public function description(): ?string
    {
        return 'Who has accepted which policy or procedure, at which revision, and on which node. Acceptances are immutable: an acknowledgment of an earlier revision stays an acknowledgment of that revision. Narrow by organization or by the department a requirement was asked in.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.document-acknowledgments',
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            DocumentAcknowledgmentListLayout::class,
        ];
    }

    /**
     * Organization / department narrowing shared by the query and the rendered
     * controls. An acknowledgment is asked at one of those two levels and never
     * at a team's (POL-026), so the team control is absent rather than present
     * and always empty.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(DocumentAcknowledgment::class);
    }
}
