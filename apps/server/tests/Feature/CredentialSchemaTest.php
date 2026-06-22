<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCredential;
use App\Models\Organization;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CredentialSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_credentials_table_has_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('event_credentials'));

        foreach ([
            'id',
            'event_id',
            'staff_id',
            'status',
            'status_reason',
            'changed_by_user_id',
            'created_at',
            'updated_at',
            'revoked_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('event_credentials', $column),
                "event_credentials.{$column} missing",
            );
        }
    }

    public function test_event_credentials_use_uuid_primary_keys(): void
    {
        $credential = EventCredential::factory()->create();

        $this->assertTrue(Str::isUuid($credential->id));
    }

    public function test_at_most_one_credential_per_staff_member_per_event(): void
    {
        $event = Event::factory()->create();
        $staff = Staff::factory()->create();

        EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
        ]);

        $this->expectException(QueryException::class);

        EventCredential::factory()->create([
            'event_id' => $event->id,
            'staff_id' => $staff->id,
        ]);
    }

    public function test_events_table_supports_minimum_staff_age(): void
    {
        $this->assertTrue(Schema::hasColumn('events', 'minimum_staff_age'));

        $event = Event::factory()->create(['minimum_staff_age' => 18]);

        $this->assertSame(18, $event->minimum_staff_age);
    }

    public function test_event_credential_statuses_match_documented_values(): void
    {
        $this->assertSame(
            ['eligible', 'blocked', 'revoked'],
            EventCredential::statuses(),
        );
    }
}
