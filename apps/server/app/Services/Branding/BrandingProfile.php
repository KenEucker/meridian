<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Team;

/**
 * Everything a surface needs to render an organization's identity, resolved
 * once (M15A.4, M15A.8; BRAND-001 through BRAND-005, BRAND-013).
 *
 * A read model rather than the Eloquent rows, because the shell, the generated
 * stylesheet, the PDF exporter, the mailer, and the client bootstrap all need
 * the same answers — display name, mark, palette, whether department overrides
 * are on — and each of them working it out from columns would be four chances
 * to disagree about, say, whether a blank display name means "use the legal
 * name" or "use Meridian".
 */
final class BrandingProfile
{
    /**
     * @param  array<string, DepartmentBranding>  $departments  keyed by department id
     * @param  array<string, TeamBranding>  $teams  keyed by team id
     */
    private function __construct(
        public readonly ?string $organizationId,
        public readonly string $displayName,
        public readonly BrandingPalette $palette,
        public readonly bool $isBranded,
        /**
         * Whether the organization chose a palette, as opposed to merely
         * having a name or a logo.
         *
         * Separate from {@see $isBranded} because the two drive different
         * things and conflating them was a bug: an organization that uploaded
         * a logo but never opened the colour pickers had Meridian's default
         * palette applied *as if chosen*, which then overrode the dark theme
         * and painted a white background at night. Identity replacement
         * (BRAND-002) and palette replacement (BRAND-006) are separate
         * decisions and an organization may make either without the other.
         */
        public readonly bool $hasCustomPalette,
        public readonly bool $departmentOverridesEnabled,
        public readonly ?string $fullLockupAttachmentId,
        public readonly ?string $compactMarkAttachmentId,
        public readonly array $departments = [],
        public readonly array $teams = [],
        /**
         * The event this install is locked to, or null when it is not locked
         * to one (BRAND-028). Present even when that event has no mark of its
         * own, because "locked to an event that has no logo" and "not locked to
         * an event" are different states and only the first has a name to show.
         */
        public readonly ?EventBranding $lockedEvent = null,
    ) {}

    /**
     * The profile every surface BRAND-003 protects renders with, and the one
     * an organization with no branding profile gets.
     */
    public static function meridian(): self
    {
        return new self(
            organizationId: null,
            displayName: 'Meridian',
            palette: BrandingPalette::meridianDefault(),
            isBranded: false,
            hasCustomPalette: false,
            departmentOverridesEnabled: true,
            fullLockupAttachmentId: null,
            compactMarkAttachmentId: null,
        );
    }

    /**
     * @param  iterable<Department>  $departments
     * @param  iterable<Team>  $teams
     */
    public static function forOrganization(
        Organization $organization,
        iterable $departments = [],
        iterable $teams = [],
        ?Event $lockedEvent = null,
    ): self {
        $overridesEnabled = (bool) $organization->department_branding_enabled;
        $resolved = [];

        foreach ($departments as $department) {
            $resolved[(string) $department->id] = DepartmentBranding::forDepartment(
                $department,
                $overridesEnabled,
            );
        }

        $resolvedTeams = [];

        foreach ($teams as $team) {
            $resolvedTeams[(string) $team->id] = TeamBranding::forTeam($team);
        }

        return new self(
            organizationId: (string) $organization->id,
            displayName: $organization->brandingDisplayName(),
            palette: BrandingPalette::fromStored($organization->branding_palette_json),
            isBranded: $organization->hasBrandingProfile(),
            hasCustomPalette: $organization->branding_palette_json !== null,
            departmentOverridesEnabled: $overridesEnabled,
            fullLockupAttachmentId: $organization->branding_full_lockup_attachment_id !== null
                ? (string) $organization->branding_full_lockup_attachment_id
                : null,
            compactMarkAttachmentId: $organization->branding_compact_mark_attachment_id !== null
                ? (string) $organization->branding_compact_mark_attachment_id
                : null,
            departments: $resolved,
            teams: $resolvedTeams,
            lockedEvent: $lockedEvent instanceof Event
                ? EventBranding::forEvent($lockedEvent)
                : null,
        );
    }

    /**
     * The name that replaces Meridian's on the surfaces BRAND-002 names.
     *
     * Distinct from {@see $displayName}, which falls back to the legal
     * organization name so an admin surface always has something to show. An
     * organization that has not set up branding has not asked to be presented
     * as itself, and stamping "Bechtelar, Denesik and Ryan LLC" on a PDF that
     * used to say "Meridian" would be a worse outcome than either.
     */
    public function identityName(): string
    {
        return $this->isBranded ? $this->displayName : 'Meridian';
    }

    /**
     * The letters shown when there is no compact mark to show (BRAND-005).
     */
    public function lettermark(): string
    {
        return Lettermark::forName($this->identityName());
    }

    public function department(string $departmentId): ?DepartmentBranding
    {
        return $this->departments[$departmentId] ?? null;
    }

    public function team(string $teamId): ?TeamBranding
    {
        return $this->teams[$teamId] ?? null;
    }

    /**
     * The mark the product's own chrome carries — application header, favicon,
     * and the desktop window icon (BRAND-005, BRAND-028).
     *
     * The locked event's mark comes first. On an install locked to an event,
     * most of the people using it were recruited by the event rather than by
     * the company producing it, and a mark they cannot place identifies nothing.
     * Where an organizer has given the event a mark, that is the one those staff
     * can recognise, so that is the one the chrome carries.
     *
     * It wins even for an organization with no branding profile of its own,
     * because uploading an event logo is itself a deliberate act — there is no
     * reading of it under which the organizer wanted it stored and not shown.
     *
     * After that it is the organization's, compact mark before full lockup: an
     * icon renders at 32 pixels or less, where a wide lockup becomes a smear.
     * Null means render the generated lettermark.
     */
    public function chromeMarkAttachmentId(): ?string
    {
        if ($this->showsEventIdentity()) {
            return $this->lockedEvent->logoAttachmentId;
        }

        return $this->compactMarkAttachmentId ?? $this->fullLockupAttachmentId;
    }

    /**
     * The name shown beside the chrome mark (BRAND-029).
     *
     * The mark and the name are one identity and move together. A header
     * carrying the event's logo next to the producing company's name — or
     * worse, next to "Meridian" — is the same failure the event mark exists to
     * fix: it asks a staff member to recognise something they have no reason to
     * know, and it does it in the one place they look to confirm they are in
     * the right app.
     *
     * Keyed on the event having a *logo*, not merely on the install being
     * event-locked. An event that has set nothing has not asked to be presented
     * as the product, and a locked install with no event mark should read
     * exactly as it did before the event existed.
     *
     * This is chrome only. Generated PDF exports and system email keep
     * {@see identityName()}, because those leave the product: a document or a
     * message naming an event but not the organization behind it gives its
     * recipient no accountable party.
     */
    public function chromeIdentityName(): string
    {
        return $this->showsEventIdentity()
            ? $this->lockedEvent->name
            : $this->identityName();
    }

    /**
     * Whether this install presents the locked event's identity rather than the
     * organization's.
     *
     * @phpstan-assert-if-true !null $this->lockedEvent
     */
    public function showsEventIdentity(): bool
    {
        return $this->lockedEvent !== null
            && $this->lockedEvent->logoAttachmentId !== null;
    }

    /**
     * What the shell writes into `data-organization-branding` (UI
     * implementation contract 10.3).
     */
    public function documentAttribute(): string
    {
        return $this->isBranded ? 'applied' : 'default';
    }
}
