<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;

/**
 * One named part of the offline read set, and the module that owns it.
 *
 * A section is a list of rows under a name a client stores them by. The module
 * is the MOD-016 boundary carried on the section rather than checked at the
 * call site: a section states what it belongs to, and the composer drops the
 * ones whose module the organization does not run. A section with no module is
 * core (MOD-004) and always travels.
 *
 * A section is also either stored rows or computed ones. Almost every section is
 * the first: the device holds what the database holds and derives the rest for
 * itself. The Planning Table's plan-versus-actual rows are the second — they are
 * counts *of* identities the device is deliberately not given (SLB-019), so the
 * node computes them and the device cannot recompute them. That distinction is
 * what {@see self::aggregate()} marks, and what lets the set report a freshness
 * for the computed rows without putting a clock inside the version.
 */
final class OfflineReadSetSection
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public readonly string $name,
        public readonly array $rows,
        public readonly ?ModuleKey $module = null,
        public readonly bool $computed = false,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function core(string $name, array $rows): self
    {
        return new self($name, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function owned(string $name, ModuleKey $module, array $rows): self
    {
        return new self($name, $rows, $module);
    }

    /**
     * Rows the node computed, which the device cannot recompute.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function aggregate(string $name, ModuleKey $module, array $rows): self
    {
        return new self($name, $rows, $module, computed: true);
    }
}
