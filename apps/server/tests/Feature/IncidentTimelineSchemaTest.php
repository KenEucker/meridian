<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IncidentTimelineSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_timeline_entry_belongs_to_incident_and_actor(): void
    {
        $incident = Incident::factory()->create();
        $entry = IncidentTimelineEntry::factory()->forIncident($incident)->create([
            'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
            'body' => 'Operational note.',
            'previous_value' => ['status' => 'open'],
            'new_value' => ['status' => 'monitoring'],
            'reason' => 'Status changed with note.',
        ]);

        $this->assertSame($incident->id, $entry->incident->id);
        $this->assertSame('Operational note.', $entry->body);
        $this->assertSame(['status' => 'open'], $entry->previous_value);
        $this->assertSame(['status' => 'monitoring'], $entry->new_value);
        $this->assertSame($entry->id, $incident->timelineEntries()->sole()->id);
    }

    public function test_incident_timeline_entries_are_not_edited_or_deleted(): void
    {
        $entry = IncidentTimelineEntry::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incident timeline entries are append-only and cannot be edited.');

        $entry->forceFill(['body' => 'Changed body.'])->save();
    }

    public function test_incident_timeline_entries_are_not_deleted(): void
    {
        $entry = IncidentTimelineEntry::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incident timeline entries are operational history and cannot be deleted.');

        $entry->delete();
    }
}
