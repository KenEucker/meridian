<?php

declare(strict_types=1);

namespace App\Services\Kiosk;

use RuntimeException;

/**
 * A Kiosk pinned-context change was refused (M18.32; UI-019, UI-021; technical
 * spec 13.1).
 *
 * One reason code per refusal, for the same reason the shared-workstation login
 * refusals carry one: the surface explaining it is the Kiosk setup screen on the
 * machine being pinned, and it should not have to read prose to know what
 * happened.
 */
final class KioskPinnedContextException extends RuntimeException
{
    /** The workstation is not a trusted shared workstation on this node. */
    public const REASON_WORKSTATION_UNTRUSTED = 'workstation_untrusted';

    /** The workstation has no pinned organization, so only God Mode can pin it. */
    public const REASON_ORGANIZATION_UNPINNED = 'workstation_organization_unpinned';

    /** The chosen event belongs to another organization. */
    public const REASON_EVENT_OUT_OF_SCOPE = 'event_out_of_scope';

    /** The chosen department does not participate in the chosen event. */
    public const REASON_DEPARTMENT_NOT_PARTICIPATING = 'department_not_participating';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function workstationUntrusted(): self
    {
        return new self(
            self::REASON_WORKSTATION_UNTRUSTED,
            'That workstation is not a trusted shared workstation on this node.',
            403,
        );
    }

    /**
     * Technical spec 13.1: shared workstations are managed in God Mode, and a
     * workstation with no pinned organization is a workstation nobody has yet
     * said whose site it stands on. There is no organization to hold an
     * organizer's authority against, so the first pin is God Mode's and the
     * refusal says so rather than failing an authority check that would read as
     * "you are not an organizer".
     */
    public static function organizationUnpinned(): self
    {
        return new self(
            self::REASON_ORGANIZATION_UNPINNED,
            'This workstation has no pinned organization yet. A God Mode operator pins it to an organization before an organizer can choose its event.',
            409,
        );
    }

    public static function eventOutOfScope(): self
    {
        return new self(
            self::REASON_EVENT_OUT_OF_SCOPE,
            'A workstation can only be pinned to an event its own organization produces.',
        );
    }

    public static function departmentNotParticipating(string $departmentName): self
    {
        return new self(
            self::REASON_DEPARTMENT_NOT_PARTICIPATING,
            sprintf(
                '%s does not work this event, so a workstation cannot be pinned to it here.',
                $departmentName,
            ),
        );
    }
}
