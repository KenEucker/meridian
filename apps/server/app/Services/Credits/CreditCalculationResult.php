<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\CreditLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What one credit calculation run did (CREDIT-001 through CREDIT-004).
 *
 * The counts are reported rather than summarized into a single "succeeded"
 * flag because the three outcomes mean different things to an operator: entries
 * were written, entries already existed and were left frozen, or hours could
 * not be credited because no policy governs them and someone has to configure
 * one.
 */
final readonly class CreditCalculationResult
{
    /**
     * @param  Collection<int, CreditLedgerEntry>  $entries  Entries written by this run, in calculation order.
     */
    public function __construct(
        public Collection $entries,
        public int $alreadyCalculatedCount,
        public int $unresolvedPolicyCount,
        public string $totalHours,
        public string $totalCredits,
        public CarbonInterface $calculatedAt,
    ) {}

    public function createdCount(): int
    {
        return $this->entries->count();
    }
}
