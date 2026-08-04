<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\EventAuthorityException;
use App\Services\Organizations\OrganizationConfigurationGovernance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product-path administration of an organization's credit policies (M18.16;
 * ORG-009, ORG-020; CREDIT-002, CREDIT-003; data/API section 10.12).
 *
 * The table has existed since M13.5 and the resolver has read it since, but
 * the only way to put a policy in it was a tinker session — an organization
 * could not actually start running credits from the product. This is that way.
 *
 * Policies are priced history's input, so the same three protections the
 * incident type list carries apply here: archive rather than delete (a shift
 * may still point at a policy, and CREDIT-002 says an archived policy keeps
 * governing the shifts that name it), rename and re-rate in place (frozen
 * ledger entries carry their own copy of the name and multiplier in
 * `calculation_basis`, so history does not move when the policy does), and
 * per-organization case-insensitive name uniqueness (two spellings of one rate
 * would split an event's credits across them).
 *
 * Unlike the incident type list, edits here **freeze during the active event
 * window and belong to central** ({@see OrganizationConfigurationGovernance};
 * ORG-021). The grace period and thresholds froze because changing them
 * mid-event changes the rules of an event already being played by them, and a
 * credit rate is the clearest such rule there is: it prices the very hours the
 * event is producing.
 */
final class CreditPolicyAdminService
{
    public function __construct(
        private readonly OrganizationConfigurationGovernance $governance,
        private readonly AuditService $audit,
    ) {}

    /**
     * @throws CreditPolicyAdminException
     * @throws EventAuthorityException
     */
    public function create(
        Organization $organization,
        string $name,
        mixed $multiplier,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): CreditPolicy {
        $this->governance->assertEditable((string) $organization->getKey());

        $name = $this->validName($name);
        $multiplier = $this->validMultiplier($multiplier);

        $this->assertNameAvailable($organization, $name);

        return DB::transaction(function () use ($organization, $name, $multiplier, $actor, $sourceContext): CreditPolicy {
            $policy = CreditPolicy::query()->create([
                'organization_id' => (string) $organization->getKey(),
                'name' => $name,
                'credit_multiplier' => $multiplier,
            ]);

            $this->audit->recordForEntity(
                entity: $policy,
                action: 'credit_policy.created',
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                after: $this->snapshot($policy),
                sourceContext: $sourceContext,
            );

            return $policy->refresh();
        });
    }

    /**
     * Rename and re-rate in place. Frozen ledger entries are untouched by
     * either — they carry the name and multiplier they were calculated at in
     * `calculation_basis` (CREDIT-005) — so a re-rate changes only what future
     * runs will price at.
     *
     * @throws CreditPolicyAdminException
     * @throws EventAuthorityException
     */
    public function update(
        CreditPolicy $policy,
        string $name,
        mixed $multiplier,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): CreditPolicy {
        $organization = $this->organizationFor($policy);
        $this->governance->assertEditable((string) $organization->getKey());

        $name = $this->validName($name);
        $multiplier = $this->validMultiplier($multiplier);

        $this->assertNameAvailable($organization, $name, $policy);

        return DB::transaction(function () use ($policy, $name, $multiplier, $actor, $organization, $sourceContext): CreditPolicy {
            $before = $this->snapshot($policy);

            $policy->forceFill([
                'name' => $name,
                'credit_multiplier' => $multiplier,
            ])->save();

            $after = $this->snapshot($policy->refresh());

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $policy,
                    action: 'credit_policy.updated',
                    actorUser: $actor,
                    organizationId: (string) $organization->getKey(),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $policy;
        });
    }

    /**
     * @throws CreditPolicyAdminException
     * @throws EventAuthorityException
     */
    public function archive(
        CreditPolicy $policy,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): CreditPolicy {
        if ($policy->isArchived()) {
            throw CreditPolicyAdminException::invalid('This credit policy is already archived.');
        }

        $organization = $this->organizationFor($policy);

        // The organization default is the answer for every shift that names no
        // policy of its own (CREDIT-003). Archiving it while it holds that job
        // would quietly stop crediting most of the organization's work, so the
        // default has to be moved or cleared first — a deliberate second step.
        if ((string) $organization->default_credit_policy_id === (string) $policy->getKey()) {
            throw CreditPolicyAdminException::invalid(
                'This policy is the organization default. Choose another default, or clear it, before archiving this one.',
            );
        }

        return $this->setArchivedAt($policy, Carbon::now(), 'credit_policy.archived', $actor, $sourceContext);
    }

    /**
     * @throws CreditPolicyAdminException
     * @throws EventAuthorityException
     */
    public function restore(
        CreditPolicy $policy,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): CreditPolicy {
        if (! $policy->isArchived()) {
            throw CreditPolicyAdminException::invalid('This credit policy is not archived.');
        }

        // A name freed up while this one was archived may have been taken.
        $this->assertNameAvailable($this->organizationFor($policy), (string) $policy->name, $policy);

        return $this->setArchivedAt($policy, null, 'credit_policy.restored', $actor, $sourceContext);
    }

    /**
     * @throws CreditPolicyAdminException
     * @throws EventAuthorityException
     */
    private function setArchivedAt(
        CreditPolicy $policy,
        ?Carbon $archivedAt,
        string $action,
        User $actor,
        string $sourceContext,
    ): CreditPolicy {
        $organization = $this->organizationFor($policy);
        $this->governance->assertEditable((string) $organization->getKey());

        return DB::transaction(function () use ($policy, $archivedAt, $action, $actor, $organization, $sourceContext): CreditPolicy {
            $before = $this->snapshot($policy);

            $policy->forceFill(['archived_at' => $archivedAt])->save();

            $this->audit->recordForEntity(
                entity: $policy,
                action: $action,
                actorUser: $actor,
                organizationId: (string) $organization->getKey(),
                before: $before,
                after: $this->snapshot($policy),
                sourceContext: $sourceContext,
            );

            return $policy->refresh();
        });
    }

    /**
     * How many shifts currently name a policy, for the surface's usage note.
     * Read here so the controller and any later console caller agree on what
     * "in use" means: shifts pointing at the policy, cancelled or not, because
     * a cancelled shift restored tomorrow still prices at it.
     */
    public function shiftCountFor(CreditPolicy $policy): int
    {
        return Shift::query()
            ->where('credit_policy_id', (string) $policy->getKey())
            ->count();
    }

    private function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw CreditPolicyAdminException::invalid('Credit policy name is required.');
        }

        if (mb_strlen($name) > 100) {
            throw CreditPolicyAdminException::invalid(
                'Credit policy name may not be greater than 100 characters.',
            );
        }

        return $name;
    }

    /**
     * Credits per hour worked, to at most three decimal places — the precision
     * the column holds, refused rather than silently rounded away.
     */
    private function validMultiplier(mixed $multiplier): string
    {
        if ($multiplier === null || ! is_numeric($multiplier)) {
            throw CreditPolicyAdminException::invalid('Credit policy multiplier is required.');
        }

        $value = (float) $multiplier;

        if ($value <= 0 || $value > 1000) {
            throw CreditPolicyAdminException::invalid(
                'The credit multiplier must be greater than 0 and at most 1000 credits per hour.',
            );
        }

        if (round($value, 3) !== $value) {
            throw CreditPolicyAdminException::invalid(
                'The credit multiplier holds at most three decimal places.',
            );
        }

        return number_format($value, 3, '.', '');
    }

    /**
     * Refuse a name the organization already uses, whatever its casing.
     *
     * Archived policies are included: the row still holds the name, and
     * restoring one later has to land somewhere.
     */
    private function assertNameAvailable(
        Organization $organization,
        string $name,
        ?CreditPolicy $ignore = null,
    ): void {
        $query = CreditPolicy::query()
            ->where('organization_id', (string) $organization->getKey())
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($ignore !== null) {
            $query->whereKeyNot($ignore->getKey());
        }

        if ($query->exists()) {
            throw CreditPolicyAdminException::invalid(
                sprintf('This organization already has a credit policy named %s.', $name),
            );
        }
    }

    private function organizationFor(CreditPolicy $policy): Organization
    {
        $policy->loadMissing('organization');
        $organization = $policy->organization;

        if (! $organization instanceof Organization) {
            throw CreditPolicyAdminException::invalid('This credit policy has no organization.');
        }

        return $organization;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CreditPolicy $policy): array
    {
        return [
            'id' => (string) $policy->getKey(),
            'organization_id' => (string) $policy->organization_id,
            'name' => $policy->name,
            'credit_multiplier' => (string) $policy->credit_multiplier,
            'archived_at' => $policy->archived_at?->toIso8601String(),
        ];
    }
}
