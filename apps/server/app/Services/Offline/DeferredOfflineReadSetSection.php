<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;

/**
 * A section of technical spec 9.3 that this build cannot compose yet, named in
 * the response rather than left out of it.
 *
 * The retired sync rules made the same call — they ended with a comment listing
 * what they deferred and why — and the reason holds under any transport. A
 * client reading a set with no `notes` key cannot tell "you
 * authored none" from "this build has no Notes", and those are different facts
 * for a device deciding what it can show a person with no signal.
 *
 * A deferral is not a module being inactive. An inactive module's section is
 * absent because the organization does not run it (MOD-016); a deferred
 * section is absent because Meridian has not built it yet, and it names the
 * milestone that will.
 */
final class DeferredOfflineReadSetSection
{
    public function __construct(
        public readonly string $name,
        public readonly string $reason,
        public readonly string $owningWork,
        public readonly ?ModuleKey $module = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->name,
            'module' => $this->module?->value,
            'reason' => $this->reason,
            'owning_work' => $this->owningWork,
        ];
    }
}
