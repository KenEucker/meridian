<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Team;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Team;
use App\Orchid\Layouts\Team\TeamBrandingLayout;
use App\Orchid\Layouts\Team\TeamEditLayout;
use App\Orchid\Support\BrandingScreenSupport;
use App\Services\Branding\Lettermark;
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
    use BrandingScreenSupport;

    /**
     * @var Team
     */
    public $team;

    /**
     * @return array<string, mixed>
     */
    public function query(Team $team): iterable
    {
        $logoUrl = $this->brandingAssetUrl($team->branding_logo_attachment_id);

        return [
            'team' => $team,
            'branding' => [
                'logo_url' => $logoUrl,
            ],
            'branding_slots' => [
                [
                    'label' => __('Team logo'),
                    'url' => $logoUrl,
                    'lettermark' => Lettermark::forName((string) $team->name),
                ],
            ],
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
        $layouts = [
            Layout::block(TeamEditLayout::class)
                ->title(__('Team'))
                ->description(__('Teams replace department roles and persist across events.')),
        ];

        // Branding needs a team to attach to, which arrives with the first
        // save — the same reason the department screen defers it.
        if ($this->team->exists) {
            $layouts[] = Layout::view('orchid.branding.assets');
            $layouts[] = Layout::block(TeamBrandingLayout::class)
                ->title(__('Branding'))
                ->description($this->brandingDescription());
        }

        return $layouts;
    }

    private function brandingDescription(): string
    {
        $base = __(
            'A logo only. Accent and background colors belong to the department this team sits in, so a team mark '
            .'identifies without re-coloring a surface the department already colors.'
        );

        $this->team->loadMissing('department');

        $organizationId = $this->team->department?->organization_id !== null
            ? (string) $this->team->department->organization_id
            : null;

        $lock = $this->brandingLockReason($organizationId);

        return $lock !== null ? $base.' '.$lock : $base;
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

        $team->loadMissing('department');

        $this->applyBrandingAsset(
            $request,
            $team,
            Attachment::BRANDING_SLOT_TEAM_LOGO,
            'branding.logo',
            'branding.remove_logo',
        );

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
