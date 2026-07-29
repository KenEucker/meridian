<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ApiToken;

use App\Models\ApiToken;
use App\Models\User;
use App\Orchid\Filters\ApiToken\ApiTokenDeviceFilter;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Issued bearer tokens, by user and by device (AUTH-022; data/API 12.5).
 *
 * The token value is not a column and cannot become one: only its SHA-256 hash
 * is stored, and raw token values are never exported (AUTH-025). What an
 * operator identifies a token by is its client name, its owner, its bound
 * device, and when it was last used.
 */
class ApiTokenListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'tokens';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Client'))
                ->sort()
                ->cantHide()
                ->render(fn (ApiToken $token) => e((string) $token->name)),

            TD::make('user', __('User'))
                ->render(function (ApiToken $token) {
                    $owner = $token->tokenable;

                    return $owner instanceof User
                        ? e($owner->name.' — '.$owner->email)
                        : __('Unknown user');
                }),

            TD::make('device', __('Device'))
                ->render(function (ApiToken $token) {
                    $device = $token->device;

                    if ($device === null) {
                        return __('Unknown device');
                    }

                    // Links to this device's own tokens, which is also where
                    // revoking all of them becomes available.
                    return Link::make(ApiTokenDeviceFilter::label($device))
                        ->route('platform.api-tokens', [
                            ApiTokenDeviceFilter::PARAMETER => $device->getKey(),
                        ]);
                }),

            TD::make('status', __('Status'))
                ->render(fn (ApiToken $token) => $token->statusLabel()),

            TD::make('created_at', __('Issued'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make('last_used_at', __('Last used'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make('expires_at', __('Expires'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('140px')
                ->render(fn (ApiToken $token) => Button::make(__('Revoke token'))
                    ->icon('bs.slash-circle')
                    ->confirm(__('Revoke this token? The client holding it stops authenticating on its next request and has to sign in again. This is audited.'))
                    ->method('revokeToken', [
                        'token' => $token->getKey(),
                    ])
                    ->canSee(! $token->isRevoked())),
        ];
    }
}
