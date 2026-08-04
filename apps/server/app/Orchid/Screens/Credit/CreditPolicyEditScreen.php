<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Credit;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Credit\CreditPolicyEditLayout;
use App\Services\Credits\CreditPolicyAdminException;
use App\Services\Credits\CreditPolicyAdminService;
use App\Services\Node\EventAuthorityException;
use App\Services\Organizations\OrganizationConfigurationException;
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
 * God Mode's editor for one credit policy (M18.16).
 *
 * Every change routes through {@see CreditPolicyAdminService}, so the Orchid
 * path and the organizer product path write the same audit rows and enforce
 * the same rules — including the ORG-021 governance freeze during an active
 * event window and the refusal to archive the organization default. A screen
 * that talked to the model directly would be a second set of rules that could
 * drift from the one organizers use.
 */
class CreditPolicyEditScreen extends Screen
{
    /**
     * @var CreditPolicy
     */
    public $creditPolicy;

    /**
     * @return array<string, CreditPolicy>
     */
    public function query(CreditPolicy $creditPolicy): iterable
    {
        return [
            'creditPolicy' => $creditPolicy,
        ];
    }

    public function name(): ?string
    {
        return $this->creditPolicy->exists ? 'Edit credit policy' : 'Add credit policy';
    }

    public function description(): ?string
    {
        return 'One rate worked hours are credited at, for one organization.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.credit-policies',
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
                ->route('platform.credit-policies'),

            Button::make(__('Archive'))
                ->icon('bs.archive')
                ->method('archive')
                ->canSee($this->creditPolicy->exists && $this->creditPolicy->archived_at === null),

            Button::make(__('Restore'))
                ->icon('bs.arrow-counterclockwise')
                ->method('restore')
                ->canSee($this->creditPolicy->exists && $this->creditPolicy->archived_at !== null),

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
            Layout::block(CreditPolicyEditLayout::class)
                ->title(__('Credit policy'))
                ->description(__(
                    'Credit policies price worked hours per organization. The organization default credits any '
                    .'shift that names no policy of its own; archiving one keeps it on the shifts that already '
                    .'name it. Edits freeze during the organization\'s active event window, here and on the '
                    .'product surface both.'
                )),
        ];
    }

    public function save(
        Request $request,
        CreditPolicy $creditPolicy,
        CreditPolicyAdminService $policies,
    ): RedirectResponse {
        $rules = [
            'creditPolicy.name' => ['required', 'string', 'max:100'],
            'creditPolicy.credit_multiplier' => ['required', 'numeric'],
        ];

        if (! $creditPolicy->exists) {
            $rules['creditPolicy.organization_id'] = ['required', 'uuid', Rule::exists(Organization::class, 'id')];
        }

        $validated = $request->validate($rules);
        $name = (string) $validated['creditPolicy']['name'];
        $multiplier = $validated['creditPolicy']['credit_multiplier'];

        try {
            if ($creditPolicy->exists) {
                $policies->update($creditPolicy, $name, $multiplier, $this->actor($request), AuditEvent::SOURCE_ORCHID);
            } else {
                $organization = Organization::query()
                    ->findOrFail((string) $validated['creditPolicy']['organization_id']);

                $policies->create(
                    $organization,
                    $name,
                    $multiplier,
                    $this->actor($request),
                    AuditEvent::SOURCE_ORCHID,
                );
            }
        } catch (EventAuthorityException|OrganizationConfigurationException|CreditPolicyAdminException $exception) {
            throw ValidationException::withMessages([
                'creditPolicy.name' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('Credit policy saved.'));

        return redirect()->route('platform.credit-policies');
    }

    public function archive(
        Request $request,
        CreditPolicy $creditPolicy,
        CreditPolicyAdminService $policies,
    ): RedirectResponse {
        return $this->transition(
            fn (): CreditPolicy => $policies->archive($creditPolicy, $this->actor($request), AuditEvent::SOURCE_ORCHID),
            __('Credit policy archived.'),
        );
    }

    public function restore(
        Request $request,
        CreditPolicy $creditPolicy,
        CreditPolicyAdminService $policies,
    ): RedirectResponse {
        return $this->transition(
            fn (): CreditPolicy => $policies->restore($creditPolicy, $this->actor($request), AuditEvent::SOURCE_ORCHID),
            __('Credit policy restored.'),
        );
    }

    private function transition(callable $apply, string $message): RedirectResponse
    {
        try {
            $apply();
        } catch (EventAuthorityException|OrganizationConfigurationException|CreditPolicyAdminException $exception) {
            Toast::error($exception->getMessage());

            return back();
        }

        Toast::info($message);

        return redirect()->route('platform.credit-policies');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
