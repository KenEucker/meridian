<?php

namespace App\Console\Commands;

use App\Models\CreditLedgerEntry;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Models\Node;
use App\Services\Credits\CreditCalculationException;
use App\Services\Credits\CreditCalculationService;
use App\Services\Node\NodeSetupService;
use Illuminate\Console\Command;

/**
 * Calculate credits for events whose hours have finished settling (M18.16;
 * CREDIT-001 through CREDIT-004).
 *
 * Runs on the scheduler daily, so an organization that configures a policy and
 * walks away still gets its ledger written once the grace period closes —
 * pressing the button on the credit policy surface is the same run, just
 * sooner and by name.
 *
 * The command only visits events that have frozen hours no calculated entry
 * covers yet, which is what keeps the daily run quiet: an event fully credited
 * yesterday matches no query today, writes nothing, and audits nothing. The
 * calculation service holds the actual gates — the configured grace period
 * must have closed and no hours record may still be open — and an event one of
 * those gates refuses is skipped for this run rather than treated as an error,
 * because tomorrow's run will pick it up once the gate opens.
 *
 * Only where credits are owned: ledger entries are governance data central is
 * authoritative for, so an on-site node refuses quietly the same way the
 * lifecycle evaluator does (ORG-021's shape). A standalone or development node
 * is its own central.
 */
class CalculateEventCreditsCommand extends Command
{
    protected $signature = 'meridian:calculate-event-credits';

    protected $description = 'Write frozen credit ledger entries for events whose correction grace period has closed';

    /**
     * Node roles that own the credit ledger.
     *
     * @var list<string>
     */
    private const AUTHORITATIVE_ROLES = [
        Node::ROLE_CENTRAL,
        Node::ROLE_STANDALONE,
        Node::ROLE_DEVELOPMENT,
    ];

    public function handle(
        NodeSetupService $nodes,
        CreditCalculationService $calculation,
    ): int {
        $node = $nodes->activeNode();

        if ($node instanceof Node
            && ! in_array((string) $node->node_role, self::AUTHORITATIVE_ROLES, true)) {
            $this->info('This node does not own the credit ledger; nothing to calculate.');

            return self::SUCCESS;
        }

        $created = 0;
        $calculated = 0;
        $waiting = 0;

        foreach ($this->eventsWithUncreditedHours() as $event) {
            try {
                $result = $calculation->calculateForEvent($event);
            } catch (CreditCalculationException) {
                // The grace period is still open or hours are still
                // unfrozen; the event stays on tomorrow's list.
                $waiting++;

                continue;
            }

            $created += $result->createdCount();
            $calculated++;
        }

        $this->info(sprintf(
            'Event credits calculated: %d entr%s written across %d event(s), %d event(s) still settling.',
            $created,
            $created === 1 ? 'y' : 'ies',
            $calculated,
            $waiting,
        ));

        return self::SUCCESS;
    }

    /**
     * Events holding frozen hours that no calculated ledger entry covers.
     *
     * This is the work test, not the eligibility test — eligibility belongs to
     * the calculation service. Reading it here keeps the daily run from
     * re-running (and re-auditing) every settled event forever.
     *
     * @return list<Event>
     */
    private function eventsWithUncreditedHours(): array
    {
        $eventIds = HoursWorked::query()
            ->whereNotNull('frozen_at')
            ->whereNotExists(fn ($query) => $query
                ->select('id')
                ->from('credit_ledger_entries')
                ->whereColumn('credit_ledger_entries.hours_worked_id', 'hours_worked.id')
                ->where('credit_ledger_entries.entry_type', CreditLedgerEntry::ENTRY_TYPE_CALCULATED))
            ->distinct()
            ->pluck('event_id');

        return Event::query()
            ->whereIn('id', $eventIds->all())
            ->orderBy('ends_at')
            ->get()
            ->all();
    }
}
