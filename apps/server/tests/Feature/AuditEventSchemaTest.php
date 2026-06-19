<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AuditEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_events_table_has_documented_columns(): void
    {
        $expected = [
            'id',
            'organization_id',
            'event_id',
            'department_id',
            'actor_user_id',
            'actor_device_id',
            'actor_node_id',
            'action',
            'entity_type',
            'entity_id',
            'before_json',
            'after_json',
            'reason',
            'source_context',
            'signature_metadata_json',
            'created_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn('audit_events', $column),
                "audit_events should have a {$column} column.",
            );
        }
    }

    public function test_audit_events_have_no_updated_at_column(): void
    {
        $this->assertFalse(Schema::hasColumn('audit_events', 'updated_at'));
    }

    public function test_audit_events_use_uuid_primary_keys(): void
    {
        $event = AuditEvent::factory()->create();

        $this->assertFalse($event->getIncrementing());
        $this->assertSame('string', $event->getKeyType());
        $this->assertTrue(Str::isUuid($event->getKey()));
    }

    public function test_json_columns_are_cast_to_arrays(): void
    {
        $event = AuditEvent::factory()->create([
            'before_json' => ['status' => 'prospective'],
            'after_json' => ['status' => 'active'],
            'signature_metadata_json' => ['algo' => 'ed25519'],
        ]);

        $event->refresh();

        $this->assertSame(['status' => 'prospective'], $event->before_json);
        $this->assertSame(['status' => 'active'], $event->after_json);
        $this->assertSame(['algo' => 'ed25519'], $event->signature_metadata_json);
        $this->assertNotNull($event->created_at);
    }

    public function test_audit_events_cannot_be_updated(): void
    {
        $event = AuditEvent::factory()->create();

        $this->expectException(RuntimeException::class);

        $event->update(['action' => 'tampered']);
    }

    public function test_audit_events_cannot_be_deleted(): void
    {
        $event = AuditEvent::factory()->create();

        $this->expectException(RuntimeException::class);

        $event->delete();
    }
}
