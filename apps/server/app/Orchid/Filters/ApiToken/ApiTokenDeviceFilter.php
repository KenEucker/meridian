<?php

declare(strict_types=1);

namespace App\Orchid\Filters\ApiToken;

use App\Models\ApiToken;
use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

/**
 * Narrows the God Mode token list to one device (AUTH-022).
 *
 * Selecting a device is also what makes "revoke every token on this device"
 * available on the screen, because revoking a device's tokens is a decision an
 * operator should make while looking at exactly the tokens it will end.
 */
final class ApiTokenDeviceFilter extends Filter
{
    public const PARAMETER = 'token_device';

    public function name(): string
    {
        return __('Device');
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

        return $builder->forDevice($selected);
    }

    public function display(): iterable
    {
        return [
            Select::make(self::PARAMETER)
                ->options($this->options())
                ->empty(__('All devices'))
                ->value($this->selected())
                ->title(__('Device')),
        ];
    }

    public function value(): string
    {
        $device = Device::query()->find($this->selected());

        return $this->name().': '.($device?->device_label ?? __('All devices'));
    }

    /**
     * @return array<string, string>
     */
    private function options(): array
    {
        return Device::query()
            ->whereIn('id', ApiToken::query()->select('device_id'))
            ->orderBy('device_label')
            ->get()
            ->mapWithKeys(fn (Device $device): array => [
                (string) $device->getKey() => self::label($device),
            ])
            ->all();
    }

    public static function label(Device $device): string
    {
        $label = $device->device_label.' ('.$device->platform.')';

        return $device->isRevoked()
            ? $label.' — '.__('revoked')
            : $label;
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
