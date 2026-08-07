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
     * The three record screens M18.34 adds, each under the heading that says
     * what it is for. Acknowledgment review is the other half of maintaining a
     * document, so it files with the documents; the two break-glass reads are
     * repair tooling, so they file under God Mode.
     */
    public function test_the_record_screens_sit_under_the_headings_that_explain_them(): void
    {
        $this->assertSame('Policies & Procedures', $this->sectionFor('Document Acknowledgments'));
        $this->assertSame('God Mode', $this->sectionFor('Field Reports'));
        $this->assertSame('God Mode', $this->sectionFor('Incidents'));

        $response = $this->actingAs($this->consoleUser([
            'platform.index' => true,
            'platform.document-acknowledgments' => true,
            'platform.field-reports' => true,
            'platform.incidents' => true,
        ]))->get(route('platform.main'));

        $response->assertOk();
        $response->assertSee('Document Acknowledgments');
        $response->assertSee('Field Reports');
        $response->assertSee('Incidents');
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
