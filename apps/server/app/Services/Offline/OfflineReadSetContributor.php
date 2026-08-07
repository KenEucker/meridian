<?php

declare(strict_types=1);

namespace App\Services\Offline;

/**
 * One group of technical spec 9.3's cache lists, composed for a resolved scope.
 *
 * Section 9.3 is written as one regular-staff list plus five role-additive ones
 * — Logistics, Operations, Planning, shift lead, department lead — and a
 * contributor is one of those lists. Splitting them this way is what lets
 * M18.47 add the additive ones without editing the regular-staff composition,
 * and what keeps each list's scope rule next to the rows it produces.
 *
 * A contributor receives the scope and never the request. It reads what the
 * scope authorizes and answers with sections; it makes no authorization
 * decision of its own beyond the rule its own list states, and it writes
 * nothing.
 */
interface OfflineReadSetContributor
{
    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array;

    /**
     * Sections of this contributor's list that this build cannot compose yet.
     *
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array;
}
