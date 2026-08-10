<?php

declare(strict_types=1);

namespace App\Orchid\Screens\SharedWorkstation;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Orchid\Layouts\SharedWorkstation\SharedWorkstationListLayout;
use App\Orchid\Layouts\SharedWorkstation\SharedWorkstationPinLayout;
use App\Services\Kiosk\KioskPinnedContextException;
use App\Services\Kiosk\KioskPinnedContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * God Mode Kiosk pinned context (M18.32; UI-019 through UI-021; technical spec
 * 13.1).
 *
 * "Shared workstations are managed in God mode", and until this task nothing
 * managed them: the pinned organization, event, and department columns have been
 * on `shared_workstations` since M16.8 and only a seeder ever wrote them. A Kiosk
 * that finished an event stayed pinned to it, and a machine that had never been
 * pinned sat in setup with nothing anybody could do about it.
 *
 * This screen is the first pin and the repair path. The ordinary change — moving
 * a workstation to next weekend's event — is an organizer's, from the Kiosk setup
 * screen on the machine itself (UI-021), and goes through the same service and
 * writes the same audit entry.
 *
 * What this screen does not do is create workstations or trust devices. A shared
 * workstation is a kind of trusted device (13.1) and trusting one is node
 * pairing, which is its own path with its own token.
 */
class SharedWorkstationListScreen extends Screen
{
    public const PERMISSION = 'platform.shared-workstations';

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'workstations' => SharedWorkstation::query()
                ->with(['organization', 'event', 'department', 'device'])
                ->orderBy('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Shared Workstations';
    }

    public function description(): ?string
    {
        return 'Trusted shared workstations on this node and the Kiosk context each is pinned to. A workstation with no pinned organization and event puts its Kiosk into setup; it cannot be signed in to, because a login code is scoped to a pinned event. The identifier is what a machine in setup asks for — read it from here and type it into that machine. It is not a credential: signing in still needs a login code issued to a named person at a trusted workstation.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            self::PERMISSION,
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(SharedWorkstationPinLayout::class)
                ->title(__('Pin a Kiosk context'))
                ->description(__('Pinning ends whatever session the workstation is holding, because that session was signed in to the previous context. Every change is audited.'))
                ->commands(
                    Button::make(__('Pin context'))
                        ->icon('bs.pin-map')
                        ->method('pin'),
                ),

            SharedWorkstationListLayout::class,
        ];
    }

    /**
     * Pin a workstation to an organization, an event, and optionally a
     * department (technical spec 13.1).
     */
    public function pin(Request $request, KioskPinnedContextService $pinnedContext): RedirectResponse
    {
        $operator = $this->authorizedOperator();

        $validated = $request->validate([
            'pin.shared_workstation_id' => ['required', 'string', 'max:64'],
            'pin.organization_id' => ['nullable', 'string', 'max:64'],
            'pin.event_id' => ['required', 'string', 'max:64'],
            'pin.department_id' => ['nullable', 'string', 'max:64'],
        ]);

        $workstation = SharedWorkstation::query()->find($validated['pin']['shared_workstation_id']);
        $event = Event::query()->find($validated['pin']['event_id']);

        if (! $workstation instanceof SharedWorkstation || ! $event instanceof Event) {
            Toast::warning(__('That workstation or event is no longer on record.'));

            return $this->back();
        }

        $organizationId = $validated['pin']['organization_id'] ?? null;
        $departmentId = $validated['pin']['department_id'] ?? null;

        try {
            $pinnedContext->pin(
                workstation: $workstation,
                event: $event,
                department: $departmentId === null ? null : Department::query()->find($departmentId),
                actor: $operator,
                organization: $organizationId === null ? null : Organization::query()->find($organizationId),
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        } catch (KioskPinnedContextException $exception) {
            return $this->back()->withErrors([
                'pin.event_id' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('«:name» is pinned to :event. Its Kiosk leaves setup on the next read.', [
            'name' => $workstation->name,
            'event' => $event->name,
        ]));

        return $this->back();
    }

    /**
     * The screen already gates on the permission; this checks it again because a
     * screen method is its own request and is not reached through the screen's
     * own authorization.
     */
    private function authorizedOperator(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasAccess(self::PERMISSION), 403);

        return $user;
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('platform.shared-workstations');
    }
}
