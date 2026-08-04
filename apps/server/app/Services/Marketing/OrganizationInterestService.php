<?php

namespace App\Services\Marketing;

use App\Domain\Marketing\OrganizationInterestOutcome;
use App\Models\AuditEvent;
use App\Models\OrganizationInquiry;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Organization interest submitted from the public marketing surface (M18.23;
 * PUBLIC-002 through PUBLIC-005).
 *
 * The form collects four things and creates one row (PUBLIC-002, PUBLIC-003).
 * That constraint is the interesting part of this service rather than an
 * incidental one: a public form that could create an organization would be a
 * way for anybody on the internet to put a tenant into a Meridian deployment,
 * and PUBLIC-004 keeps organization creation a God Mode action taken by a
 * person who decided to take it. So nothing here touches organizations, users,
 * staff, or any operational table. It writes an inquiry and audits that it did.
 *
 * PUBLIC-005 asks for two things at once — protection against automated
 * submission, and no challenge that blocks legitimate use — which rules out a
 * CAPTCHA and leaves three cheaper measures that a person never meets:
 *
 *  - two rate limits, in {@see OrganizationInterestThrottle};
 *  - a hidden field no human fills in, whose submissions are discarded;
 *  - a minimum time on the form, whose submissions are returned for a second
 *    press rather than discarded.
 *
 * The two traps are answered differently on purpose, and the reasoning is in
 * {@see OrganizationInterestOutcome}.
 */
class OrganizationInterestService
{
    public const DEFAULT_MINIMUM_SECONDS_ON_FORM = 3;

    public function __construct(
        private readonly AuditService $audit,
        private readonly OrganizationInterestThrottle $throttle,
    ) {}

    /**
     * Record an organization's interest, or decide not to.
     *
     * The throttle is consulted before either trap, so how many submissions a
     * client may make never depends on how many of them were believed.
     *
     * @param  array{organization_name: string, contact_name: string, contact_email: string, description: string}  $fields
     * @param  string|null  $clientKey  the submitting client, normally its IP address
     * @param  bool  $trapFieldFilled  whether the hidden field carried a value
     * @param  Carbon|null  $formRenderedAt  when this visitor was given the form
     *
     * @throws OrganizationInterestRateLimitException
     */
    public function submit(
        array $fields,
        ?string $clientKey = null,
        bool $trapFieldFilled = false,
        ?Carbon $formRenderedAt = null,
    ): OrganizationInterestSubmission {
        $contactEmail = $this->normalizeEmail($fields['contact_email']);

        $this->throttle->guard($contactEmail, $clientKey);

        if ($trapFieldFilled) {
            // Audited even though nothing is stored, so an operator who has to
            // ask whether the trap is eating real submissions can answer it.
            // A trap that misfires silently is indistinguishable from a quiet
            // week, and the rate limits above bound how much of this an
            // automated client can write.
            $this->recordAudit(
                action: 'organization_inquiry.discarded',
                entityId: (string) Str::uuid(),
                after: ['reason' => 'hidden_field_completed'],
                reason: 'Organization interest submission discarded: a field no visitor can see was filled in.',
            );

            return new OrganizationInterestSubmission(OrganizationInterestOutcome::Discarded);
        }

        if ($this->isImplausiblyFast($formRenderedAt)) {
            return new OrganizationInterestSubmission(OrganizationInterestOutcome::TooFast);
        }

        $inquiry = OrganizationInquiry::query()->create([
            'organization_name' => trim($fields['organization_name']),
            'contact_name' => trim($fields['contact_name']),
            'contact_email' => $contactEmail,
            'description' => trim($fields['description']),
            'status' => OrganizationInquiry::STATUS_NEW,
            'submitted_at' => now(),
        ]);

        // PUBLIC-005. No organization scope, because there is no organization:
        // that is the whole point of the record. The free-text description is
        // deliberately not copied into the audit payload — it is the one field
        // a stranger wrote at length, it lives on the row the audit entry
        // names, and duplicating it would spread somebody's message across two
        // tables for no reading anybody needs.
        $this->recordAudit(
            action: 'organization_inquiry.submitted',
            entityId: (string) $inquiry->getKey(),
            after: [
                'organization_name' => $inquiry->organization_name,
                'contact_name' => $inquiry->contact_name,
                'contact_email' => $inquiry->contact_email,
                'status' => $inquiry->status,
            ],
            reason: 'Organization interest submitted from the marketing surface.',
        );

        return new OrganizationInterestSubmission(OrganizationInterestOutcome::Recorded, $inquiry);
    }

    /**
     * Record a God Mode review decision (PUBLIC-004).
     *
     * Statuses move freely, including back to `new`: a closed inquiry that the
     * organization writes in about again is an inquiry somebody needs to look
     * at, and refusing to reopen it would push that conversation out of the
     * console and into somebody's inbox.
     */
    public function review(
        OrganizationInquiry $inquiry,
        string $status,
        ?string $reviewNotes,
        User $reviewer,
    ): OrganizationInquiry {
        $before = [
            'status' => $inquiry->status,
            'review_notes' => $inquiry->review_notes,
        ];

        $inquiry->forceFill([
            'status' => $status,
            'review_notes' => $reviewNotes,
            'reviewed_by_user_id' => $reviewer->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->recordAudit(
            action: 'organization_inquiry.reviewed',
            entityId: (string) $inquiry->getKey(),
            actor: $reviewer,
            before: $before,
            after: [
                'status' => $inquiry->status,
                'review_notes' => $inquiry->review_notes,
            ],
            reason: 'Organization inquiry reviewed in the God Mode console.',
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );

        return $inquiry;
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * The floor a submission has to clear, in seconds on the form.
     *
     * Configurable, and settable to zero: a deployment behind its own bot
     * filtering may not want a timing rule at all, and unlike the rate limits
     * this one has a way of catching a real person. Zero switches it off; a
     * missing or non-numeric setting falls back to the documented default.
     */
    public function minimumSecondsOnForm(): int
    {
        $configured = config(
            'meridian.marketing.interest_minimum_seconds_on_form',
            self::DEFAULT_MINIMUM_SECONDS_ON_FORM,
        );

        if (! is_numeric($configured)) {
            return self::DEFAULT_MINIMUM_SECONDS_ON_FORM;
        }

        return max(0, (int) $configured);
    }

    /**
     * A submission with no known render time is not treated as fast. The
     * session that carried it may simply have expired, and holding that against
     * the visitor would punish the slowest submissions rather than the quickest.
     */
    private function isImplausiblyFast(?Carbon $formRenderedAt): bool
    {
        $minimum = $this->minimumSecondsOnForm();

        if ($minimum === 0 || ! $formRenderedAt instanceof Carbon) {
            return false;
        }

        return $formRenderedAt->diffInSeconds(now(), absolute: true) < $minimum;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordAudit(
        string $action,
        string $entityId,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_WEB,
    ): void {
        $this->audit->record(
            action: $action,
            entityType: 'organization_inquiry',
            entityId: $entityId,
            actorUser: $actor,
            before: $before,
            after: $after,
            reason: $reason,
            sourceContext: $sourceContext,
        );
    }
}
