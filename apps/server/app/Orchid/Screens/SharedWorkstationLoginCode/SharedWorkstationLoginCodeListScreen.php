<?php

declare(strict_types=1);

namespace App\Orchid\Screens\SharedWorkstationLoginCode;

use App\Models\Event;
use App\Models\SharedWorkstation;
use App\Models\SharedWorkstationLoginCode;
use App\Models\User;
use App\Orchid\Layouts\SharedWorkstationLoginCode\SharedWorkstationLoginCodeGenerateLayout;
use App\Orchid\Layouts\SharedWorkstationLoginCode\SharedWorkstationLoginCodeListLayout;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use App\Services\Auth\SharedWorkstationLoginException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * God Mode shared-workstation login codes: generate a code for a known user,
 * list outstanding and spent codes, and revoke one (AUTH-026, AUTH-028,
 * AUTH-029; technical spec 13.2; data/API 12.4).
 *
 * This is the assisted-recovery and event-preparation half of 13.2. The other
 * half — a user generating their own code from a device where they already hold a
 * session — is `POST /api/auth/shared-workstation-login-code`, and needs no
 * console at all, because its whole reason for existing is that nobody with a
 * console may be reachable.
 *
 * A generated code is shown once, on this page, immediately after it is created.
 * It is stored only as a keyed hash, so nothing here can display it again, and
 * 13.2 rules out printing or exporting codes as event prep sheets — which is why
 * the list has no export and the code has no column.
 */
class SharedWorkstationLoginCodeListScreen extends Screen
{
    public const PERMISSION = 'platform.shared-workstation-login-codes';

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'login_codes' => SharedWorkstationLoginCode::query()
                ->with(['user', 'generatedByUser', 'sharedWorkstation.device', 'event'])
                ->defaultSort('created_at', 'desc')
                ->paginate(),
            // Flashed by `generate()` for a single render, exactly as the one-time
            // node pairing token is. There is no second chance to read it.
            'generatedCode' => session('meridian.generated_workstation_login_code'),
            'generatedCodeUser' => session('meridian.generated_workstation_login_code_user'),
            'generatedCodeWorkstation' => session('meridian.generated_workstation_login_code_workstation'),
            'generatedCodeExpiresAt' => session('meridian.generated_workstation_login_code_expires_at'),
        ];
    }

    public function name(): ?string
    {
        return 'Workstation Login Codes';
    }

    public function description(): ?string
    {
        return 'Human-typable codes that sign a known user in to a trusted shared workstation. Codes are scoped to one user and one event, name one workstation or none — an unbound code binds to the first trusted workstation it is used at — are valid for six weeks, and are stored only as hashes: a code is shown once when it is generated and never again.';
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
     * Nothing in the command bar: generating a code is the only action this screen
     * takes, and it belongs beside the two fields that decide who it is for rather
     * than at the top of a page whose form may be untouched.
     *
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
            Layout::block(SharedWorkstationLoginCodeGenerateLayout::class)
                ->title(__('Generate a login code'))
                ->description(__('God Mode may generate a code for any known user. A user may also generate their own from a device where they already hold a session, which is the path that works when the node has no internet.'))
                ->commands(
                    Button::make(__('Generate code'))
                        ->icon('bs.key')
                        ->method('generate'),
                ),

            Layout::view('orchid.shared-workstation-login-code'),

            SharedWorkstationLoginCodeListLayout::class,
        ];
    }

    /**
     * Generate a code for another user (AUTH-026, AUTH-028), targeted at a
     * workstation or unbound (AUTH-031).
     */
    public function generate(Request $request, SharedWorkstationLoginCodeService $loginCodes): RedirectResponse
    {
        $operator = $this->authorizedOperator();

        $validated = $request->validate([
            'login_code.user_id' => ['required', 'string', 'max:64'],
            'login_code.shared_workstation_id' => ['nullable', 'string', 'max:64'],
            'login_code.event_id' => ['nullable', 'string', 'max:64'],
        ]);

        $subject = User::query()->find($validated['login_code']['user_id']);

        if (! $subject instanceof User) {
            Toast::warning(__('That user is no longer on record.'));

            return $this->back();
        }

        $workstation = null;
        $event = null;

        if (($validated['login_code']['shared_workstation_id'] ?? null) !== null) {
            $workstation = SharedWorkstation::query()->find($validated['login_code']['shared_workstation_id']);

            if (! $workstation instanceof SharedWorkstation) {
                Toast::warning(__('That workstation is no longer on record.'));

                return $this->back();
            }
        } elseif (($validated['login_code']['event_id'] ?? null) !== null) {
            $event = Event::query()->find($validated['login_code']['event_id']);

            if (! $event instanceof Event) {
                Toast::warning(__('That event is no longer on record.'));

                return $this->back();
            }
        }

        try {
            $issued = $loginCodes->generateForUser($subject, $operator, $workstation, $event);
        } catch (SharedWorkstationLoginException $exception) {
            return $this->back()->withErrors([
                'login_code.shared_workstation_id' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('A login code was generated for :name. Read it to them now; it is not shown again.', [
            'name' => $subject->name,
        ]));

        // The flash says which kind was issued (M18.58): a targeted code names
        // its workstation, an unbound one says where it will bind instead.
        return $this->back()
            ->with('meridian.generated_workstation_login_code', $issued->formattedCode())
            ->with('meridian.generated_workstation_login_code_user', $subject->name)
            ->with('meridian.generated_workstation_login_code_workstation', $workstation instanceof SharedWorkstation
                ? $workstation->name
                : __('any trusted workstation pinned to :event — it binds to the first one it is used at', [
                    'event' => $event?->name,
                ]))
            ->with('meridian.generated_workstation_login_code_expires_at', $issued->record->expires_at?->toDayDateTimeString());
    }

    /**
     * Withdraw a code before it is used (technical spec 13.2).
     */
    public function revokeCode(Request $request, SharedWorkstationLoginCodeService $loginCodes): RedirectResponse
    {
        $operator = $this->authorizedOperator();

        $code = SharedWorkstationLoginCode::query()->find($request->input('login_code'));

        if (! $code instanceof SharedWorkstationLoginCode) {
            Toast::warning(__('That login code is no longer on record.'));

            return $this->back();
        }

        $loginCodes->revoke($code, $operator)
            ? Toast::info(__('Login code revoked. It no longer works at that workstation.'))
            : Toast::warning(__('That login code was already used, revoked, or expired.'));

        return $this->back();
    }

    /**
     * The console screen already gates on the permission; these methods check it
     * again because a screen method is its own request and is not reached through
     * the screen's own authorization.
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
        return redirect()->route('platform.shared-workstation-login-codes');
    }
}
