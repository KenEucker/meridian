<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Audit;

use App\Models\AuditEvent;
use App\Orchid\Layouts\Audit\AuditListLayout;
use App\Orchid\Layouts\ScopeFiltersLayout;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * The God Mode audit trail (M18.34; requirements 2.4; data/API 14.1; UI
 * contract 12.9).
 *
 * This is the repair view of the same rows `organizer.audit` serves, and it
 * differs from that surface in three deliberate ways:
 *
 *  - **It spans organizations.** The product surface answers for one
 *    organization, resolved from the reader's own standing. This one is a
 *    node-wide trail narrowed by choice, which is why it carries the same
 *    organization / department / team filter bar the other God Mode lists do.
 *  - **It carries node and system history.** Rows with no organization —
 *    pairing, node configuration, device trust — are outside any organizer's
 *    scope and are exactly what somebody debugging a node needs.
 *  - **It excludes nothing.** ORG-015 keeps incident and Field Report history
 *    out of what *organizing* reaches; it is not a rule about repair tooling,
 *    and a support operator who cannot see that an incident was reopened cannot
 *    answer why it looks the way it does. The gate is `platform.audit`, which is
 *    console access rather than staff standing.
 *
 * Read-only, and not by convention: {@see AuditEvent} refuses updates and
 * deletes at the model, so there is no write path to offer here even if a
 * screen wanted one.
 */
class AuditListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'entries' => AuditEvent::query()
                ->with(['actorUser', 'organization', 'department', 'event'])
                ->filters($this->scopeFilters()->filters())
                ->filters()
                ->defaultSort('created_at', 'desc')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Audit Trail';
    }

    public function description(): ?string
    {
        return 'Every recorded change on this node: who made it, what it was made to, when, and why. Append-only and read-only. Narrow by organization, department, or team, or open an entry for the recorded values.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.audit',
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            AuditListLayout::class,
        ];
    }

    /**
     * Organization / department / team narrowing shared by the query and the
     * rendered controls, so what is displayed and what is applied cannot drift.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(AuditEvent::class);
    }
}
