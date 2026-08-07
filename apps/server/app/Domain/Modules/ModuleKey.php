<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * The Alpha 1 module catalogue (MOD-002).
 *
 * Eight optional capability groups over a core that is never disableable
 * (MOD-004). The catalogue is fixed in Meridian's own code for a given build:
 * an organization chooses which of these it runs and cannot define, install, or
 * extend one (MOD-003).
 *
 * This enum is the catalogue and nothing else. Where module state is *stored*
 * per organization — the entitled/enabled pair of MOD-005 — is M19.11's, and
 * {@see \App\Services\Modules\ActiveModuleResolver} is the single seam that
 * will read it. Naming the eight keys here now is what lets the offline read
 * set declare which module owns each of its sections, which is the MOD-016
 * boundary the set has to hold whether or not the storage exists yet.
 */
enum ModuleKey: string
{
    case Scheduling = 'scheduling';

    case IncidentManagement = 'ims';

    case Documents = 'documents';

    case Qualifications = 'qualifications';

    case Equipment = 'equipment';

    case EventGeography = 'geography';

    case Briefing = 'briefing';

    case Insights = 'insights';

    /**
     * The human name MOD-002 gives this module, for a message that has to say
     * which module an absence belongs to (MOD-013).
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduling => 'Scheduling',
            self::IncidentManagement => 'Incident Management',
            self::Documents => 'Documents',
            self::Qualifications => 'Qualifications',
            self::Equipment => 'Equipment',
            self::EventGeography => 'Event Geography',
            self::Briefing => 'The Briefing',
            self::Insights => 'Insights',
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $module): string => $module->value, self::cases());
    }
}
