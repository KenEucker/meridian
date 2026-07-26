<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * Canonical Event Info section vocabulary and assembly order (M11.20).
 *
 * Event Info is the staff-facing answer to "how do I get there, what do I need,
 * and what is expected of me". Before M11.20 the screen carried hand-written
 * placeholder prose, which is the one thing an event-information page must
 * never be: staff cannot tell placeholder guidance from real guidance, and the
 * placeholder has no author, no version, and no publication decision behind it.
 *
 * The selection rule is therefore deliberately boring. A maintainer assigns a
 * policy or procedure document to exactly one Event Info section while
 * authoring it, and Event Info renders published documents for that section.
 * Nothing is inferred from titles or slugs, because a page that guesses which
 * document means "directions" will eventually guess wrong in front of a staff
 * member driving to a gate at night.
 *
 * Source references:
 * - Requirements POL-003, POL-006, POL-008 through POL-012, POL-022, POL-040.
 * - Technical spec section 21.3 (states and scope) and 21.7 (rendering).
 * - UI implementation contract section 12.3 `event.info`.
 */
final class EventInfoSection
{
    public const DIRECTIONS = 'directions';

    public const ARRIVAL = 'arrival';

    public const PACKING = 'packing';

    public const FOOD = 'food';

    public const HOUSING = 'housing';

    public const REQUIREMENTS = 'requirements';

    /**
     * Section order is the order a staff member needs the answers in: find the
     * event, get through the gate, arrive with the right things, then eat,
     * sleep, and meet what the event expects.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::DIRECTIONS,
            self::ARRIVAL,
            self::PACKING,
            self::FOOD,
            self::HOUSING,
            self::REQUIREMENTS,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::DIRECTIONS => 'How to get to the event',
            self::ARRIVAL => 'Arrival requirements',
            self::PACKING => 'What to bring',
            self::FOOD => 'Food',
            self::HOUSING => 'Housing',
            self::REQUIREMENTS => 'Event requirements',
        ];
    }

    /**
     * Shown when a section has no visible published document, so the empty
     * state names what is missing instead of implying the answer is elsewhere.
     *
     * @return array<string, string>
     */
    public static function emptyDescriptions(): array
    {
        return [
            self::DIRECTIONS => 'No published document covers travel, gate access, or arrival checkpoints for this event yet.',
            self::ARRIVAL => 'No published document covers arrival requirements, credentials, or check-in for this event yet.',
            self::PACKING => 'No published document covers what staff should bring to this event yet.',
            self::FOOD => 'No published document covers meals or food availability for this event yet.',
            self::HOUSING => 'No published document covers housing, camping, or shelter for this event yet.',
            self::REQUIREMENTS => 'No published document covers what this event requires of staff yet.',
        ];
    }

    public static function isValid(?string $section): bool
    {
        return $section !== null && in_array($section, self::keys(), true);
    }

    public static function label(?string $section): ?string
    {
        return self::labels()[$section] ?? null;
    }

    public static function emptyDescription(string $section): string
    {
        return self::emptyDescriptions()[$section]
            ?? 'No published document covers this part of the event yet.';
    }

    /**
     * Assembly order within a section: broadest guidance first, then the
     * narrowing scopes, then title. A staff member reads the organization rule
     * before the department exception to it, and two documents at the same
     * scope never trade places between requests.
     */
    public static function scopeRank(string $scopeType): int
    {
        return match ($scopeType) {
            'organization' => 0,
            'department' => 1,
            'team' => 2,
            default => 3,
        };
    }
}
