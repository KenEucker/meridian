<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ApiToken;

use App\Orchid\Filters\ApiToken\ApiTokenDeviceFilter;
use App\Orchid\Filters\ApiToken\ApiTokenUserFilter;
use Orchid\Screen\Layouts\Selection;

/**
 * "By user" and "by device" narrowing for the God Mode token list (AUTH-022).
 *
 * Rendered as an always-visible bar rather than a dropdown, because AUTH-022
 * describes listing tokens by user and by device as the way this screen is
 * read, not as an occasional refinement of it.
 */
final class ApiTokenFiltersLayout extends Selection
{
    /**
     * @var string
     */
    public $template = self::TEMPLATE_LINE;

    /**
     * @return iterable<class-string<\Orchid\Filters\Filter>>
     */
    public function filters(): iterable
    {
        return [
            ApiTokenUserFilter::class,
            ApiTokenDeviceFilter::class,
        ];
    }
}
