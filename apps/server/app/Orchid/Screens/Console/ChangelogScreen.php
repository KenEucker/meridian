<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Console;

use App\Services\Console\Changelog;
use App\Services\Console\ChangelogRefresh;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * The in-console Changelog page (GOD-018 through GOD-026; technical spec 22.7).
 *
 * This replaces the external administrative-framework changelog link. It
 * renders the changelog data file packaged with the deployment, grouped by the
 * Meridian version each change shipped in, and it renders that way whether or
 * not a network exists.
 *
 * Refresh is requested after the response is sent, so the page never waits for
 * the source repository (GOD-025).
 */
class ChangelogScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(Changelog $changelog, ChangelogRefresh $refresh): iterable
    {
        $releases = $changelog->withPullRequestUrls(
            $changelog->merge($changelog->releases(), $refresh->entries()),
        );

        // Requested only after the page has been rendered from what is already
        // on disk and in cache, so a slow source repository cannot delay it.
        $refresh->refreshAfterResponse();

        return [
            'packaged' => $changelog->isPackaged(),
            'releases' => $releases,
            'build_version' => (string) config('meridian.version'),
            'refresh' => $refresh->state(),
        ];
    }

    public function name(): ?string
    {
        return 'Changelog';
    }

    public function description(): ?string
    {
        return 'Meridian releases, grouped by the version each change shipped in.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.changelog',
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
            Layout::view('orchid.console.changelog'),
        ];
    }
}
