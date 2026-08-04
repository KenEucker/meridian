<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Credit;

use App\Models\CreditPolicy;
use App\Orchid\Layouts\Credit\CreditPolicyListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * God Mode's view of every organization's credit policies (M18.16).
 *
 * The organizer product surface at `organizer.configuration` is where an
 * organization maintains its own rates; this is the support view across all of
 * them, for onboarding an organization that has nothing configured yet or
 * repairing one that does. ORG-018 requires the product path to exist and
 * forbids configuration being reachable *only* through God Mode, which this
 * screen does not change.
 *
 * Shift-scoped rows — one shift's custom rate — are left out: each is
 * administered from the shift that carries it, on the product surface and on
 * the Orchid shift screen both.
 */
class CreditPolicyListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'creditPolicies' => CreditPolicy::query()
                ->whereNull('shift_id')
                ->with('organization')
                ->withCount('shifts')
                ->defaultSort('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Credit policies';
    }

    public function description(): ?string
    {
        return 'The rates worked hours are credited at, per organization.';
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
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.credit-policies.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            CreditPolicyListLayout::class,
        ];
    }
}
