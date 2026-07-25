<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentListPresetException;
use App\Exceptions\IncidentSearchException;
use App\Models\Event;
use App\Models\IncidentListPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Saved IMS incident list filter presets (M11.19).
 *
 * A preset is one user's named search/filter/sort selection for one event. It
 * is personal view state, not an operational record and not an authorization
 * source: it stores no incident content, and applying one still runs the normal
 * IC-gated list read. Presets are addressed by owner, so one user can never
 * read, overwrite, or delete another user's preset.
 */
final class IncidentListPresetService
{
    public function __construct(private readonly IncidentReadAccess $access) {}

    /**
     * @return Collection<int, IncidentListPreset>
     */
    public function listFor(User $user, Event $event): Collection
    {
        if (! $this->access->canViewIncidents($user, $event)) {
            return new Collection;
        }

        return IncidentListPreset::query()
            ->ownedBy($user, $event)
            ->orderBy('name')
            ->get();
    }

    /**
     * Create or overwrite the caller's preset with this name.
     *
     * Saving an existing name updates it rather than failing, so re-saving a
     * refined selection under the same name is one action instead of a
     * delete/create dance.
     */
    public function save(
        User $user,
        Event $event,
        string $name,
        IncidentSearchFilters $filters,
    ): IncidentListPreset {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw IncidentListPresetException::invalid('Preset name is required.');
        }

        if (mb_strlen($trimmed) > IncidentListPreset::MAX_NAME_LENGTH) {
            throw IncidentListPresetException::invalid(sprintf(
                'Preset name may not be greater than %d characters.',
                IncidentListPreset::MAX_NAME_LENGTH,
            ));
        }

        $existing = $this->findByName($user, $event, $trimmed);

        if ($existing === null && $this->countFor($user, $event) >= IncidentListPreset::MAX_PER_USER_PER_EVENT) {
            throw IncidentListPresetException::invalid(sprintf(
                'You already have %d saved incident list presets for this event. Delete one before saving another.',
                IncidentListPreset::MAX_PER_USER_PER_EVENT,
            ));
        }

        if ($existing !== null) {
            $existing->forceFill([
                'name' => $trimmed,
                'filters' => $filters->toSelectionArray(),
            ])->save();

            return $existing->refresh();
        }

        return IncidentListPreset::query()->create([
            'event_id' => (string) $event->getKey(),
            'user_id' => (string) $user->getKey(),
            'name' => $trimmed,
            'filters' => $filters->toSelectionArray(),
        ]);
    }

    public function delete(User $user, Event $event, string $presetId): void
    {
        $preset = IncidentListPreset::query()
            ->ownedBy($user, $event)
            ->whereKey($presetId)
            ->first();

        if ($preset === null) {
            throw IncidentListPresetException::invalid('Saved incident list preset not found.');
        }

        $preset->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloadFor(User $user, Event $event): array
    {
        return $this->listFor($user, $event)
            ->map(fn (IncidentListPreset $preset): array => $this->payload($preset))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(IncidentListPreset $preset): array
    {
        $filters = is_array($preset->filters) ? $preset->filters : [];

        try {
            $selection = IncidentSearchFilters::fromQuery($filters);
        } catch (IncidentSearchException) {
            // A preset stored before a filter vocabulary change should degrade
            // to the default list, never break the list read that carries it.
            $selection = IncidentSearchFilters::fromQuery([]);
        }

        return [
            'id' => (string) $preset->id,
            'event_id' => (string) $preset->event_id,
            'name' => $preset->name,
            'filters' => $selection->toSelectionArray(),
            // Ready to hand straight back to the list endpoint.
            'query' => $selection->toQueryParameters(),
            'created_at' => $preset->created_at?->toIso8601String(),
            'updated_at' => $preset->updated_at?->toIso8601String(),
        ];
    }

    private function findByName(User $user, Event $event, string $name): ?IncidentListPreset
    {
        return IncidentListPreset::query()
            ->ownedBy($user, $event)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    private function countFor(User $user, Event $event): int
    {
        return IncidentListPreset::query()->ownedBy($user, $event)->count();
    }
}
