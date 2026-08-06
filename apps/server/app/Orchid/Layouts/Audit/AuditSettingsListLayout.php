<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Audit;

use App\Domain\Audit\AuditVerbosity;
use App\Models\Organization;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

/**
 * Each organization's audit configuration beside what it has actually stored.
 *
 * The measured figures sit next to the limits deliberately: a row count is the
 * only thing that makes "maximum rows" a decision rather than a guess.
 */
class AuditSettingsListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'organizations';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('name', __('Organization'))
                ->cantHide()
                ->render(fn (Organization $organization) => Link::make($organization->name)
                    ->route('platform.audit.settings.edit', $organization->id)),

            TD::make('audit_verbosity', __('Records'))
                ->render(fn (Organization $organization) => e(
                    $organization->auditVerbosity()->label()
                    .($organization->audit_verbosity === null ? ' '.__('(default)') : '')
                )),

            TD::make('overrides', __('Exceptions'))
                ->render(fn (Organization $organization) => e(
                    is_array($organization->audit_action_overrides)
                        ? (string) count($organization->audit_action_overrides)
                        : '0'
                )),

            TD::make('audit_rows', __('Rows stored'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (Organization $organization) => e(
                    number_format((int) $organization->getAttribute('audit_rows'))
                )),

            TD::make('audit_bytes', __('Estimated size'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (Organization $organization) => e(
                    $this->humanBytes((int) $organization->getAttribute('audit_bytes'))
                )),

            TD::make('limits', __('Limits'))
                ->render(fn (Organization $organization) => e($this->limits($organization))),

            TD::make('audit_oldest_at', __('Oldest entry'))
                ->align(TD::ALIGN_RIGHT)
                ->render(fn (Organization $organization) => e(
                    (string) ($organization->getAttribute('audit_oldest_at') ?? __('None'))
                )),
        ];
    }

    /**
     * The limits in one phrase, or the honest absence of them. "No limit" is
     * the state every organization is in until somebody sets one, and saying it
     * plainly beats three empty cells that could be read as zero.
     */
    private function limits(Organization $organization): string
    {
        $parts = [];

        if ($organization->audit_max_rows !== null) {
            $parts[] = number_format($organization->audit_max_rows).' rows';
        }

        if ($organization->audit_max_bytes !== null) {
            $parts[] = $this->humanBytes((int) $organization->audit_max_bytes);
        }

        if ($organization->audit_retention_days !== null) {
            $parts[] = $organization->audit_retention_days.' days';
        }

        return $parts === [] ? __('No limit') : implode(' · ', $parts);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%s %s', round($value, $index === 0 ? 0 : 1), $units[$index]);
    }
}
