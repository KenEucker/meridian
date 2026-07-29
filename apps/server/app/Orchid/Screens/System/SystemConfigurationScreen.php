<?php

declare(strict_types=1);

namespace App\Orchid\Screens\System;

use App\Services\Node\NodeSetupService;
use App\Services\SystemConfig\CatalogEntry;
use App\Services\SystemConfig\EnvExampleCatalog;
use App\Services\SystemConfig\ResolvedConfigValue;
use App\Services\SystemConfig\SystemConfigResolver;
use Illuminate\Http\Request;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * System -> Configuration: the environment/configuration catalogue with
 * effective sources and override state (technical spec 22A.7; SYS-016 through
 * SYS-021).
 *
 * The screen lists every variable `.env.example` catalogues, shows the
 * truthful effective source, and links each editable variable to its edit
 * screen. Secrets are masked before they reach the view; this screen never
 * holds a secret display value (SYS-013).
 */
class SystemConfigurationScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(
        Request $request,
        NodeSetupService $nodes,
        SystemConfigResolver $resolver,
        EnvExampleCatalog $catalog,
    ): iterable {
        $node = $nodes->activeNode();
        $values = $resolver->valuesFor($node);

        $search = trim((string) $request->query('search', ''));
        $section = (string) $request->query('section', '');
        $source = (string) $request->query('source', '');
        $state = (string) $request->query('state', '');

        $filtered = array_values(array_filter(
            $values,
            function (ResolvedConfigValue $value) use ($search, $section, $source, $state): bool {
                $entry = $value->entry;

                if ($search !== '') {
                    $haystack = strtolower(implode(' ', [
                        $entry->name,
                        $entry->label,
                        $entry->section,
                        implode(' ', $entry->configKeys),
                    ]));

                    if (! str_contains($haystack, strtolower($search))) {
                        return false;
                    }
                }

                if ($section !== '' && $entry->section !== $section) {
                    return false;
                }

                if ($source !== '' && $value->source !== $source) {
                    return false;
                }

                return match ($state) {
                    'overridden' => $value->override !== null,
                    'pending' => $value->overridePending,
                    'secret' => $entry->secret,
                    'editable' => $entry->editable(),
                    'locked' => $entry->bootstrapLocked,
                    'invalid' => $value->validationError !== null,
                    'unmapped' => ! $entry->mapped(),
                    default => true,
                };
            },
        ));

        return [
            'node' => $node,
            'values' => $filtered,
            'total' => count($values),
            'sections' => $catalog->sections(),
            'sources' => [
                ResolvedConfigValue::SOURCE_DATABASE_OVERRIDE,
                ResolvedConfigValue::SOURCE_ENVIRONMENT,
                ResolvedConfigValue::SOURCE_LARAVEL_DEFAULT,
                ResolvedConfigValue::SOURCE_MISSING,
                ResolvedConfigValue::SOURCE_INVALID,
                ResolvedConfigValue::SOURCE_UNMAPPED,
            ],
            'filters' => [
                'search' => $search,
                'section' => $section,
                'source' => $source,
                'state' => $state,
            ],
            'canManage' => $request->user()?->hasAccess('platform.system.configuration.manage') ?? false,
        ];
    }

    public function name(): ?string
    {
        return 'System Configuration';
    }

    public function description(): ?string
    {
        return 'Catalogued environment variables, their effective sources, and node-local database overrides.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.system.configuration',
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::view('orchid.system.configuration'),
        ];
    }
}
