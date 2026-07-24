<?php

declare(strict_types=1);

namespace App\Orchid\Filters\Scope;

use App\Models\Organization;
use Orchid\Screen\Fields\Select;

/**
 * Narrows a God Mode list screen to one organization.
 */
final class OrganizationScopeFilter extends ScopeFilter
{
    public const PARAMETER = 'scope_organization';

    public function name(): string
    {
        return __('Organization');
    }

    protected function scopeMethod(): string
    {
        return 'inOrganization';
    }

    protected function parameter(): string
    {
        return self::PARAMETER;
    }

    public function display(): iterable
    {
        return [
            Select::make(self::PARAMETER)
                ->options($this->options())
                ->empty(__('All organizations'))
                ->value($this->selected())
                ->title(__('Organization')),
        ];
    }

    public function value(): string
    {
        $organization = Organization::query()->find($this->selected());

        return $this->name().': '.($organization?->name ?? __('All organizations'));
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        return Organization::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
