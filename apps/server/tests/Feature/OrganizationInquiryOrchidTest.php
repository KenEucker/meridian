<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\OrganizationInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * God Mode review of organization interest submissions (M18.23; PUBLIC-004).
 */
class OrganizationInquiryOrchidTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_lists_organization_inquiries(): void
    {
        OrganizationInquiry::factory()->create([
            'organization_name' => 'Northwood Collective',
            'contact_name' => 'Jamie Rivera',
            'contact_email' => 'jamie@northwood.test',
        ]);

        $response = $this->actingAs($this->inquiryOperator())->get(route('platform.organization-inquiries'));

        $response->assertOk();
        $response->assertSee('Organization Inquiries');
        $response->assertSee('Northwood Collective');
        $response->assertSee('Jamie Rivera');
        $response->assertSee('jamie@northwood.test');
        $response->assertSee('New');
    }

    public function test_the_console_shows_what_the_organization_wrote(): void
    {
        $inquiry = OrganizationInquiry::factory()->create([
            'organization_name' => 'Northwood Collective',
            'description' => 'Two festivals a year, roughly three hundred volunteers.',
        ]);

        $response = $this->actingAs($this->inquiryOperator())
            ->get(route('platform.organization-inquiries.show', $inquiry->getKey()));

        $response->assertOk();
        $response->assertSee('Northwood Collective');
        $response->assertSee('Two festivals a year, roughly three hundred volunteers.');
    }

    public function test_marking_an_inquiry_reviewed_records_the_reviewer_and_audits_it(): void
    {
        $inquiry = OrganizationInquiry::factory()->create();
        $operator = $this->inquiryOperator();

        $response = $this->actingAs($operator)->post(
            route('platform.organization-inquiries.show', [$inquiry->getKey(), 'markReviewed']),
            ['review' => ['review_notes' => 'Replied and set up a call.']],
        );

        $response->assertRedirect(route('platform.organization-inquiries.show', $inquiry->getKey()));

        $inquiry->refresh();
        $this->assertSame(OrganizationInquiry::STATUS_REVIEWED, $inquiry->status);
        $this->assertSame('Replied and set up a call.', $inquiry->review_notes);
        $this->assertSame($operator->getKey(), $inquiry->reviewed_by_user_id);
        $this->assertNotNull($inquiry->reviewed_at);

        $audit = AuditEvent::query()
            ->where('action', 'organization_inquiry.reviewed')
            ->where('entity_id', (string) $inquiry->getKey())
            ->sole();

        $this->assertSame($operator->getKey(), $audit->actor_user_id);
        $this->assertSame(OrganizationInquiry::STATUS_NEW, $audit->before_json['status']);
        $this->assertSame(OrganizationInquiry::STATUS_REVIEWED, $audit->after_json['status']);
    }

    public function test_a_closed_inquiry_is_kept_and_can_be_reopened(): void
    {
        $inquiry = OrganizationInquiry::factory()->create();
        $operator = $this->inquiryOperator();

        $this->actingAs($operator)->post(
            route('platform.organization-inquiries.show', [$inquiry->getKey(), 'close']),
            ['review' => ['review_notes' => 'Not a fit for now.']],
        );

        $this->assertSame(OrganizationInquiry::STATUS_CLOSED, $inquiry->refresh()->status);
        $this->assertDatabaseCount('organization_inquiries', 1);

        $this->actingAs($operator)->post(
            route('platform.organization-inquiries.show', [$inquiry->getKey(), 'reopen']),
            ['review' => ['review_notes' => 'They wrote in again.']],
        );

        $this->assertSame(OrganizationInquiry::STATUS_REVIEWED, $inquiry->refresh()->status);
    }

    /**
     * PUBLIC-004: reading inquiries is its own capability, and creating an
     * organization is a separate one that this screen never exercises.
     */
    public function test_a_console_user_without_the_capability_cannot_reach_the_inquiries(): void
    {
        $inquiry = OrganizationInquiry::factory()->create();
        $user = User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.organizations' => true,
            ],
        ]);

        $this->actingAs($user)->get(route('platform.organization-inquiries'))->assertForbidden();
        $this->actingAs($user)
            ->get(route('platform.organization-inquiries.show', $inquiry->getKey()))
            ->assertForbidden();
        $this->actingAs($user)->post(
            route('platform.organization-inquiries.show', [$inquiry->getKey(), 'markReviewed']),
            ['review' => ['review_notes' => 'Not mine to decide.']],
        )->assertForbidden();

        $this->assertSame(OrganizationInquiry::STATUS_NEW, $inquiry->refresh()->status);
    }

    /**
     * PUBLIC-003, PUBLIC-004: nothing in the console turns an inquiry into an
     * organization. The absence is the requirement.
     */
    public function test_the_inquiry_screen_offers_no_path_to_creating_an_organization(): void
    {
        $inquiry = OrganizationInquiry::factory()->create();

        $response = $this->actingAs($this->inquiryOperator())
            ->get(route('platform.organization-inquiries.show', $inquiry->getKey()));

        $response->assertOk();
        $response->assertDontSee(route('platform.organizations.create'), false);
    }

    private function inquiryOperator(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.organization-inquiries' => true,
            ],
        ]);
    }
}
