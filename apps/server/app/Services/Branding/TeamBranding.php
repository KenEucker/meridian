<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Team;

/**
 * A team's mark (BRAND-025).
 *
 * One value, and that is the whole design. A team appears inside a department's
 * surface — in rosters, team pickers, shift rows, and the team overview — so a
 * team accent would put a second identity color on a screen the department
 * already colors, against a background nobody validated it for. The logo
 * identifies; the department's accent still themes.
 *
 * Unlike {@see DepartmentBranding} there is nothing here for the organization
 * override switch to turn off: BRAND-013 drops backgrounds, and a team has
 * none. A team logo renders wherever the team does, exactly as a department
 * logo does.
 */
final class TeamBranding
{
    private function __construct(
        public readonly string $teamId,
        public readonly string $departmentId,
        public readonly string $name,
        public readonly ?string $logoAttachmentId,
    ) {}

    public static function forTeam(Team $team): self
    {
        return new self(
            teamId: (string) $team->id,
            departmentId: (string) $team->department_id,
            name: (string) $team->name,
            logoAttachmentId: $team->branding_logo_attachment_id !== null
                ? (string) $team->branding_logo_attachment_id
                : null,
        );
    }

    /**
     * The letters shown when the team has no logo, matching the department
     * fallback so a mixed list of marks reads consistently (BRAND-010,
     * BRAND-025).
     */
    public function lettermark(): string
    {
        return Lettermark::forName($this->name);
    }
}
