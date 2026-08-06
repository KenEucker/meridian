<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Audit;

use App\Models\Organization;
use App\Orchid\Layouts\Audit\AuditSettingsListLayout;
use App\Services\Audit\AuditPartitioning;
use App\Services\Audit\AuditUsage;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * What each organization records and keeps (data/API 14.1; requirements 2.4).
 *
 * God Mode only for now. These controls decide how much of what happens is
 * written down and how long it survives, which is a different kind of decision
 * from the ones on the organizer configuration surface: getting it wrong does
 * not inconvenience an organization, it costs it the record of what it did. It
 * lives here until somebody decides an organizer should hold it.
 *
 * The list carries the measured usage beside the configured limits, because a
 * limit set without knowing the current figure is a guess, and the figure is
 * not available anywhere else.
 */
class AuditSettingsListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(AuditUsage $usage, AuditPartitioning $partitioning): iterable
    {
        $organizations = Organization::query()
            ->orderBy('name')
            ->get()
            ->each(function (Organization $organization) use ($usage): void {
                $measured = $usage->forOrganization($organization);

                // Attached rather than joined: the measurement is two aggregates
                // over one organization's rows, and doing it per row keeps the
                // expression readable at the cost of a query per organization on
                // a screen nobody opens in a loop.
                $organization->setAttribute('audit_rows', $measured['rows']);
                $organization->setAttribute('audit_bytes', $measured['bytes']);
                $organization->setAttribute('audit_oldest_at', $measured['oldest_at']);
            });

        return [
            'organizations' => $organizations,
            'partitioning_supported' => $partitioning->isSupported(),
            'partitioning_active' => $partitioning->isPartitioned(),
            'partitions' => $partitioning->partitions(),
        ];
    }

    public function name(): ?string
    {
        return 'Audit Settings';
    }

    public function description(): ?string
    {
        return 'How much each organization records, and how much it keeps. Required entries are always recorded whatever is set here.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.audit.settings',
        ];
    }

    /**
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.audit.storage'),
            AuditSettingsListLayout::class,
        ];
    }
}
