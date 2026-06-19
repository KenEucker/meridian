<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Team;

use App\Models\Department;
use App\Models\Team;
use App\Orchid\Layouts\Team\TeamEditLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class TeamEditScreen extends Screen
{
    /**
     * @var Team
     */
    public $team;

    /**
     * @return array<string, Team>
     */
    public function query(Team $team): iterable
    {
        return [
            'team' => $team,
        ];
    }

    public function name(): ?string
    {
        return $this->team->exists ? 'Edit Team' : 'Create Team';
    }

    public function description(): ?string
    {
        return 'Team identity and department ownership.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.teams',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.teams'),

            Button::make(__('Archive'))
                ->icon('bs.archive')
                ->method('archive')
                ->canSee($this->team->exists && ! $this->team->is_default && ! $this->team->isArchived()),

            Button::make(__('Restore'))
                ->icon('bs.arrow-counterclockwise')
                ->method('restore')
                ->canSee($this->team->exists && $this->team->isArchived()),

            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(TeamEditLayout::class)
                ->title(__('Team'))
                ->description(__('Teams replace department roles and persist across events.')),
        ];
    }

    public function save(Request $request, Team $team): RedirectResponse
    {
        $departmentId = $request->input('team.department_id');

        $validated = $request->validate([
            'team.department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'team.name' => ['required', 'string', 'max:255'],
            'team.code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique(Team::class, 'code')
                    ->where('department_id', $departmentId)
                    ->ignore($team),
            ],
            'team.description' => ['nullable', 'string'],
        ]);

        $team->fill($validated['team'])->save();

        Toast::info(__('Team was saved.'));

        return redirect()->route('platform.teams');
    }

    public function archive(Team $team): RedirectResponse
    {
        if ($team->is_default) {
            Toast::warning(__('Default teams cannot be archived in this screen.'));

            return redirect()->route('platform.teams.edit', $team);
        }

        $team->forceFill([
            'archived_at' => now(),
        ])->save();

        Toast::info(__('Team was archived.'));

        return redirect()->route('platform.teams');
    }

    public function restore(Team $team): RedirectResponse
    {
        $team->forceFill([
            'archived_at' => null,
        ])->save();

        Toast::info(__('Team was restored.'));

        return redirect()->route('platform.teams');
    }
}
