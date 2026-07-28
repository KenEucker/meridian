<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Upgrade safety for the console's visual identity (M15C.8; GOD-037).
 *
 * The identity is applied through the framework's supported configuration and
 * template extension points, so a framework upgrade does not force it to be
 * reapplied by hand. The risk this test guards is the easy shortcut: publishing
 * a vendor view, editing it, and leaving a copy of framework markup in the
 * application that silently goes stale on the next upgrade.
 *
 * Overriding a vendor view is allowed when there is no extension point for
 * what is needed. It is not allowed silently — the override inventory has to
 * say which file and why.
 */
class ConsoleUpgradeSafetyTest extends TestCase
{
    private const INVENTORY = '../../docs/process/god-mode-console-override-inventory.md';

    public function test_the_identity_is_wired_through_supported_extension_points(): void
    {
        $this->assertSame(
            ['/css/meridian-tokens.css', '/css/meridian-console.css'],
            config('platform.resource.stylesheets'),
        );
        $this->assertSame('meridian.console-header', config('platform.template.header'));
        $this->assertSame('meridian.footer', config('platform.template.footer'));

        foreach ([
            'meridian/console-header.blade.php',
            'meridian/footer.blade.php',
            'meridian/brand.blade.php',
            'meridian/surface-head.blade.php',
        ] as $view) {
            $this->assertFileExists(resource_path('views/'.$view));
        }
    }

    public function test_every_overridden_vendor_view_is_documented_in_the_override_inventory(): void
    {
        $inventory = (string) file_get_contents(base_path(self::INVENTORY));
        $published = resource_path('views/vendor/platform');

        $overrides = is_dir($published)
            ? $this->bladeFilesIn($published)
            : [];

        foreach ($overrides as $override) {
            $this->assertStringContainsString(
                $override,
                $inventory,
                "resources/views/vendor/platform/{$override} overrides a framework view. "
                .'Add it to the vendor view overrides table in '
                .'docs/process/god-mode-console-override-inventory.md with the reason and '
                .'what would remove the need (GOD-037).',
            );
        }

        if ($overrides === []) {
            $this->assertStringContainsString('**None.**', $inventory);
        }
    }

    public function test_no_framework_stylesheet_or_view_has_been_patched_in_place(): void
    {
        // The bridge overrides the framework from the outside. A patched
        // vendor asset would be reverted by `composer install` and would take
        // the identity with it.
        $this->assertDirectoryExists(base_path('public/vendor/orchid/css'));
        $this->assertFileExists(public_path('css/meridian-console.css'));

        $bridge = (string) file_get_contents(public_path('css/meridian-console.css'));
        $this->assertStringContainsString('config/platform.php', $bridge);
    }

    /**
     * @return list<string>
     */
    private function bladeFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            }
        }

        sort($files);

        return $files;
    }
}
