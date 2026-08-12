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
 * This enum is the catalogue and nothing else. Module state is *stored* per
 * organization on `organization_modules` — the entitled/enabled pair of
 * MOD-005 — and {@see \App\Services\Modules\ActiveModuleResolver} is the single
 * seam that reads it. Which of Meridian's domain namespaces each module owns is
 * {@see DomainNamespace}.
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
     * What MOD-002 says this module covers, for the organizer deciding whether
     * to run it (MOD-008).
     *
     * The node sends these to the configuration surface rather than the client
     * carrying its own copy, on the same reasoning the ORG-018 surface already
     * applies to the VOL-027 policy descriptions: what a person reads before
     * making a choice and what the product does with the choice must not be able
     * to drift apart. The Briefing's entry omits MOD-002's list of surfaces
     * Alpha 1 defers, because naming screens that do not exist yet would tell an
     * organizer less rather than more.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Scheduling => 'Shifts, shift signups, the shift board, shift training and waiver requirements, '
                .'the Schedule Desk, and the Planning Table.',
            self::IncidentManagement => 'Incidents, incident types, IMS numbers, incident timeline and links, '
                .'incident print, and Field Reports.',
            self::Documents => 'Policy documents, procedure documents, fragments, acknowledgments and '
                .'acknowledgment requirements, document exports, and waivers.',
            self::Qualifications => 'Trainings, training prerequisites, training signups and completions, and '
                .'event credential eligibility.',
            self::Equipment => 'Equipment items and equipment checkout and check-in.',
            self::EventGeography => 'Event maps, camps, map locations and features, the Placement department '
                .'designation, and deployment and location assignment.',
            self::Briefing => 'Notes, the Briefing hub, and Briefing note inclusions.',
            self::Insights => 'Insight metrics and Insight Sheets.',
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
