<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Console;

use App\Services\Console\OperatorDocumentation;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * The in-console Documentation page (GOD-012, GOD-014 through GOD-017;
 * technical spec 22.6).
 *
 * This replaces the external administrative-framework documentation link. It
 * serves Meridian's own operator documentation from content packaged with the
 * deployment, so it works on an on-site node with no internet, and it opens
 * inside the console rather than in an external browser context (GOD-027).
 */
class DocumentationScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(Request $request, OperatorDocumentation $documentation): iterable
    {
        $filter = (string) $request->query('filter', '');
        $index = $documentation->index($filter);

        $slug = (string) $request->query('doc', '');

        // A filtered-out slug would leave the reader on a document the index no
        // longer offers, so the selection follows the filter.
        $available = array_column($index, 'slug');

        if ($slug === '' || ! in_array($slug, $available, true)) {
            $slug = $available[0] ?? '';
        }

        return [
            'packaged' => $documentation->isPackaged(),
            'filter' => $filter,
            'documents' => $index,
            'selected' => $documentation->document($slug === '' ? null : $slug),
            'documentation_version' => $documentation->version(),
            'build_version' => $documentation->buildVersion(),
            'versions_match' => $documentation->matchesBuild(),
        ];
    }

    public function name(): ?string
    {
        return 'Documentation';
    }

    public function description(): ?string
    {
        return 'Meridian operator documentation packaged with this deployment.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.documentation',
        ];
    }

    /**
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
            Layout::view('orchid.console.documentation'),
        ];
    }
}
