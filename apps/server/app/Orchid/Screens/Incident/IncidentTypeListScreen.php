<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Incident;

use App\Models\IncidentType;
use App\Orchid\Layouts\Incident\IncidentTypeListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * God Mode's view of every organization's incident types (M18.14A).
 *
 * The organizer product surface at `organizer.incident-types` is where an
 * organization maintains its own list; this is the support view across all of
 * them. ORG-018 requires the product path to exist and forbids configuration
 * being reachable *only* through God Mode, which this screen does not change.
 */
class IncidentTypeListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'incidentTypes' => IncidentType::query()
                ->with('organization')
                ->withCount('incidents')
                ->defaultSort('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Incident types';
    }

    public function description(): ?string
    {
        return 'Configurable incident type labels, per organization.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.incident-types',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.incident-types.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            IncidentTypeListLayout::class,
        ];
    }
}
