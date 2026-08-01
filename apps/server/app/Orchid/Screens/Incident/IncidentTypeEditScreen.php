<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Incident;

use App\Models\AuditEvent;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Incident\IncidentTypeEditLayout;
use App\Services\Incidents\IncidentTypeAdminException;
use App\Services\Incidents\IncidentTypeAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * God Mode's editor for one incident type (M18.14A).
 *
 * Every change routes through {@see IncidentTypeAdminService}, so the Orchid
 * path and the organizer product path write the same audit rows and enforce the
 * same rules. A screen that talked to the model directly would be a second set
 * of rules that could drift from the one organizers use.
 */
class IncidentTypeEditScreen extends Screen
{
    /**
     * @var IncidentType
     */
    public $incidentType;

    /**
     * @return array<string, IncidentType>
     */
    public function query(IncidentType $incidentType): iterable
    {
        return [
            'incidentType' => $incidentType,
        ];
    }

    public function name(): ?string
    {
        return $this->incidentType->exists ? 'Edit incident type' : 'Add incident type';
    }

    public function description(): ?string
    {
        return 'One configurable incident type label for an organization.';
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
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.incident-types'),

            Button::make(__('Archive'))
                ->icon('bs.archive')
                ->method('archive')
                ->canSee($this->incidentType->exists && $this->incidentType->archived_at === null),

            Button::make(__('Restore'))
                ->icon('bs.arrow-counterclockwise')
                ->method('restore')
                ->canSee($this->incidentType->exists && $this->incidentType->archived_at !== null),

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
            Layout::block(IncidentTypeEditLayout::class)
                ->title(__('Incident type'))
                ->description(__('Incident types are configured per organization and chosen from when an incident is filed. Archiving one keeps it on the incidents that already carry it.')),
        ];
    }

    public function save(
        Request $request,
        IncidentType $incidentType,
        IncidentTypeAdminService $types,
    ): RedirectResponse {
        $rules = ['incidentType.name' => ['required', 'string', 'max:100']];

        if (! $incidentType->exists) {
            $rules['incidentType.organization_id'] = ['required', 'uuid', Rule::exists(Organization::class, 'id')];
        }

        $validated = $request->validate($rules);
        $name = (string) $validated['incidentType']['name'];

        try {
            if ($incidentType->exists) {
                $types->rename($incidentType, $name, $this->actor($request), AuditEvent::SOURCE_ORCHID);
            } else {
                $organization = Organization::query()
                    ->findOrFail((string) $validated['incidentType']['organization_id']);

                $incidentType = $types->create(
                    $organization,
                    $name,
                    $this->actor($request),
                    AuditEvent::SOURCE_ORCHID,
                );
            }
        } catch (IncidentTypeAdminException $exception) {
            throw ValidationException::withMessages([
                'incidentType.name' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('Incident type saved.'));

        return redirect()->route('platform.incident-types');
    }

    public function archive(
        Request $request,
        IncidentType $incidentType,
        IncidentTypeAdminService $types,
    ): RedirectResponse {
        return $this->transition(
            fn (): IncidentType => $types->archive($incidentType, $this->actor($request), AuditEvent::SOURCE_ORCHID),
            __('Incident type archived.'),
        );
    }

    public function restore(
        Request $request,
        IncidentType $incidentType,
        IncidentTypeAdminService $types,
    ): RedirectResponse {
        return $this->transition(
            fn (): IncidentType => $types->restore($incidentType, $this->actor($request), AuditEvent::SOURCE_ORCHID),
            __('Incident type restored.'),
        );
    }

    private function transition(callable $apply, string $message): RedirectResponse
    {
        try {
            $apply();
        } catch (IncidentTypeAdminException $exception) {
            Toast::error($exception->getMessage());

            return back();
        }

        Toast::info($message);

        return redirect()->route('platform.incident-types');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
