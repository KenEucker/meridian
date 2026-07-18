<?php

namespace Tests\Feature;

use App\Models\FieldReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldReportPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_view_own_field_report(): void
    {
        $author = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertTrue($author->can('view', $report));
    }

    public function test_non_author_cannot_view_field_report(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($otherUser->can('view', $report));
    }

    public function test_author_cannot_update_or_delete_own_field_report(): void
    {
        $author = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($author->can('update', $report));
        $this->assertFalse($author->can('delete', $report));
    }

    public function test_non_author_cannot_update_or_delete_field_report(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $report = FieldReport::factory()->forAuthor($author)->create();

        $this->assertFalse($otherUser->can('update', $report));
        $this->assertFalse($otherUser->can('delete', $report));
    }
}
