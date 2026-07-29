<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ApiToken;

use App\Models\ApiToken;
use App\Models\Device;
use App\Models\User;
use App\Orchid\Filters\ApiToken\ApiTokenDeviceFilter;
use App\Orchid\Filters\ApiToken\ApiTokenUserFilter;
use App\Orchid\Layouts\ApiToken\ApiTokenFiltersLayout;
use App\Orchid\Layouts\ApiToken\ApiTokenListLayout;
use App\Services\Auth\ApiTokenRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

/**
 * God Mode API tokens: list by user and by device, revoke one token or every
 * token on a device (AUTH-022, AUTH-023, AUTH-025; technical spec 11.4;
 * data/API 12.5).
 *
 * Revocation is stamped on the token and evaluated on the node at request time,
 * so it takes effect on the revoked client's next request whether or not that
 * client cooperates, and whether or not it is reachable when the decision is
 * made. Nothing here can display or re-issue a token value: only its hash is
 * stored (AUTH-025).
 *
 * Expired tokens stay listed. An operator asking "what could reach this node
 * from that device" is asking about credentials that existed, and a list that
 * quietly drops them answers a different question.
 */
class ApiTokenListScreen extends Screen
{
    public const PERMISSION = 'platform.api-tokens';

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'tokens' => ApiToken::query()
                ->with(['tokenable', 'device'])
                ->filters(ApiTokenFiltersLayout::class)
                ->defaultSort('created_at', 'desc')
                ->paginate(),
            'selected_device' => $this->selectedDevice(),
        ];
    }

    public function name(): ?string
    {
        return 'API Tokens';
    }

    public function description(): ?string
    {
        return 'Bearer tokens issued to client applications, listed by user and by device. Revoking a token stops it authenticating on its next request. Token values are stored only as hashes and are never shown.';
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
        $device = $this->selectedDevice();

        return [
            Button::make(__('Revoke every token on this device'))
                ->icon('bs.phone-flip')
                ->confirm(__('Revoke every token bound to this device? Each client on it stops authenticating on its next request and has to sign in again. This is audited.'))
                ->method('revokeDeviceTokens', [
                    'device' => $device?->getKey(),
                ])
                ->canSee($device instanceof Device),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            ApiTokenFiltersLayout::class,
            ApiTokenListLayout::class,
        ];
    }

    /**
     * Revoke one token (AUTH-022).
     */
    public function revokeToken(Request $request): RedirectResponse
    {
        $operator = $this->authorizedOperator();

        $token = ApiToken::query()->find($request->input('token'));

        if (! $token instanceof ApiToken) {
            Toast::warning(__('That token is no longer on record.'));

            return $this->back();
        }

        $revoked = app(ApiTokenRevoker::class)->revoke(
            $token,
            $operator,
            ApiTokenRevoker::REASON_GOD_MODE_TOKEN,
        );

        $revoked
            ? Toast::info(__('Token revoked. It stops authenticating on its next request.'))
            : Toast::warning(__('That token was already revoked.'));

        return $this->back();
    }

    /**
     * Revoke every token bound to one device (AUTH-022).
     */
    public function revokeDeviceTokens(Request $request): RedirectResponse
    {
        $operator = $this->authorizedOperator();

        $device = Device::query()->find($request->input('device'));

        if (! $device instanceof Device) {
            Toast::warning(__('That device is no longer on record.'));

            return $this->back();
        }

        $revoked = app(ApiTokenRevoker::class)->revokeForDevice($device, $operator);

        $revoked > 0
            ? Toast::info(__(':count token(s) revoked on this device.', ['count' => $revoked]))
            : Toast::warning(__('This device holds no token that was still valid.'));

        return $this->back();
    }

    /**
     * The device the list is currently narrowed to, if any.
     */
    private function selectedDevice(): ?Device
    {
        $selected = request()->get(ApiTokenDeviceFilter::PARAMETER);

        if (! is_string($selected) || trim($selected) === '') {
            return null;
        }

        return Device::query()->find($selected);
    }

    /**
     * The console screen already gates on the permission; these methods check it
     * again because a screen method is its own request and is not reached
     * through the screen's own authorization.
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
        return redirect()->route('platform.api-tokens', request()->only([
            ApiTokenDeviceFilter::PARAMETER,
            ApiTokenUserFilter::PARAMETER,
        ]));
    }
}
