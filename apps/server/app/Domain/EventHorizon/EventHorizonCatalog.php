<?php

declare(strict_types=1);

namespace App\Domain\EventHorizon;

/**
 * The Alpha 1 Event Horizon catalogue: exactly the five kinds HORIZON-003
 * fixes, in the order it fixes them.
 *
 * Defined in Meridian's own code and registered nowhere else. An organization
 * cannot author, add, remove, or reorder a kind, and no configuration surface
 * exists for the catalogue — the only organization-configurable value the whole
 * feature has is the lead-up window (technical spec 21D.4). The order below is
 * the tiebreak HORIZON-006 orders items by after state and deadline, so it is
 * part of the contract rather than a presentation choice.
 */
final class EventHorizonCatalog
{
    public const KIND_DOCUMENT_ACKNOWLEDGMENT = 'document_acknowledgment';

    public const KIND_WAIVER = 'waiver';

    public const KIND_TRAINING = 'training';

    public const KIND_SHIFT_SIGNUP = 'shift_signup';

    public const KIND_COVERAGE_GAP = 'coverage_gap';

    /**
     * @return list<EventHorizonItemKindDefinition>
     */
    public static function definitions(): array
    {
        return [
            new EventHorizonItemKindDefinition(
                id: self::KIND_DOCUMENT_ACKNOWLEDGMENT,
                label: 'Document acknowledgments',
                module: 'documents',
                governedBy: 'POL-043 through POL-047',
            ),
            new EventHorizonItemKindDefinition(
                id: self::KIND_WAIVER,
                label: 'Waivers',
                module: 'documents',
                governedBy: 'WAIVER-003 through WAIVER-006, CRED-005',
            ),
            new EventHorizonItemKindDefinition(
                id: self::KIND_TRAINING,
                label: 'Trainings',
                module: 'qualifications',
                governedBy: 'TRAIN-002, TRAIN-008, SHIFT-005',
            ),
            new EventHorizonItemKindDefinition(
                id: self::KIND_SHIFT_SIGNUP,
                label: 'Shift signup',
                module: 'scheduling',
                governedBy: 'SHIFT-004, SHIFT-007, SHIFT-008, SHIFT-011, SHIFT-018',
            ),
            new EventHorizonItemKindDefinition(
                id: self::KIND_COVERAGE_GAP,
                label: 'Team coverage',
                module: 'scheduling',
                governedBy: 'SHIFT-007, requirements 4.7',
            ),
        ];
    }

    /**
     * A kind's position in the catalogue, for the HORIZON-006 tiebreak.
     */
    public static function order(string $kindId): int
    {
        foreach (self::definitions() as $index => $definition) {
            if ($definition->id === $kindId) {
                return $index;
            }
        }

        return PHP_INT_MAX;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function describe(): array
    {
        return array_map(
            fn (EventHorizonItemKindDefinition $definition): array => $definition->describe(),
            self::definitions(),
        );
    }
}
