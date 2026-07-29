<?php

declare(strict_types=1);

namespace App\Orchid\Filters\ApiToken;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Narrows the God Mode token list to one user (AUTH-022).
 *
 * Only users who actually hold a token are offered. A node's user table is
 * every person the organization has ever registered; the token list answers a
 * narrower question, and offering the wider list would make the common case —
 * "who is signed in from what" — harder to reach rather than easier.
 */
final class ApiTokenUserFilter extends Filter
{
    public const PARAMETER = 'token_user';

    public function name(): string
    {
        return __('User');
    }

    public function parameters(): ?array
    {
        return [self::PARAMETER];
    }

    public function run(Builder $builder): Builder
    {
        $selected = $this->selected();

        if ($selected === null) {
            return $builder;
        }

        return $builder->forUser($selected);
    }

    public function display(): iterable
    {
        return [
            Select::make(self::PARAMETER)
                ->options($this->options())
                ->empty(__('All users'))
                ->value($this->selected())
                ->title(__('User')),
        ];
    }

    public function value(): string
    {
        $user = User::query()->find($this->selected());

        return $this->name().': '.($user?->name ?? __('All users'));
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        return User::query()
            ->whereIn('id', ApiToken::query()->select('tokenable_id'))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->getKey() => trim($user->name.' — '.$user->email),
            ])
            ->all();
    }

    private function selected(): ?string
    {
        $value = $this->request->get(self::PARAMETER);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
