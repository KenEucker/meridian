<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Orchid\PlatformProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchid\Screen\Actions\Menu;
use Tests\TestCase;

/**
 * God Mode console sidebar grouping.
 *
 * The sidebar's group headings live on individual menu items, so an item added
 * in the wrong place silently joins the section above it. Sync Conflicts and
 * Node Configuration read as Policies & Procedures that way; these tests pin
 * the sections so the next item added cannot repeat it.
 */
class ConsoleNavigationSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_entry_point_is_getting_started_with_no_section_heading(): void
    {
        $entryPoint = $this->menu()[0];

        $this->assertSame('Getting Started', $entryPoint->get('name'));
        $this->assertNull($entryPoint->get('title'), 'The first sidebar item must not open a section of one.');

        $response = $this->actingAs($this->consoleUser(['platform.index' => true]))
            ->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Getting Started');
        $response->assertDontSee('God Mode Home');
    }

    public function test_node_configuration_and_sync_conflicts_sit_under_infrastructure(): void
    {
        $this->assertSame('Infrastructure', $this->sectionFor('Sync Conflicts'));
        $this->assertSame('Infrastructure', $this->sectionFor('Node Configuration'));

        // The heading they used to inherit, and the one they are adjacent to.
        $this->assertSame('Policies & Procedures', $this->sectionFor('Document Fragments'));
        $this->assertSame('God Mode', $this->sectionFor('Permission Catalog'));

        $response = $this->actingAs($this->consoleUser([
            'platform.index' => true,
            'platform.permissions' => true,
            'platform.sync-conflicts' => true,
            'platform.node.config' => true,
        ]))->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Infrastructure');
        $response->assertSee('Sync Conflicts');
        $response->assertSee('Node Configuration');
    }

    /**
     * The heading an item renders under: its own title, or the closest title
     * above it.
     */
    private function sectionFor(string $name): ?string
    {
        $section = null;

        foreach ($this->menu() as $item) {
            $section = $item->get('title') ?? $section;

            if ($item->get('name') === $name) {
                return $section;
            }
        }

        $this->fail("No console menu item named '{$name}'.");
    }

    /**
     * @return list<Menu>
     */
    private function menu(): array
    {
        return array_values((new PlatformProvider(app()))->menu());
    }

    /**
     * @param  array<string, bool>  $permissions
     */
    private function consoleUser(array $permissions): User
    {
        return User::factory()->create(['permissions' => $permissions]);
    }
}
