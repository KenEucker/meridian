<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Deployment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentFragment;
use App\Models\EquipmentCheckout;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentListPreset;
use App\Models\IncidentTimelineEntry;
use App\Models\IncidentType;
use App\Models\Organization;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\Shift;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Organizations\OrganizationHostContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Which organizations a request concerns, for the module gate to decide about
 * (technical spec 15A.4; M19.12).
 *
 * The evaluation order of 15A.4 begins "resolve organization", and Meridian's
 * requests name one in three different ways. A request on an organization
 * subdomain carries it in the host (M19.8). A path-form request carries it in a
 * route parameter, either as the organization itself or as something that
 * belongs to one. A command carries it in the body, because
 * `POST /api/commands/*` names its subject by id rather than by path (data/API
 * 5.9).
 *
 * All three are read here rather than in the middleware, so the gate has one
 * question to ask and the answer means the same thing on every route.
 *
 * Two answers, deliberately different:
 *
 * - {@see self::named()} is what the request *says* it is about. A single
 *   organization named here decides the request outright.
 * - {@see self::membership()} is the caller's own organizations, and is the
 *   fallback for the few module-owned reads that name nothing — the caller's
 *   own document acknowledgments span every organization they hold a status in
 *   (data/API 5.9, `/api/document-acknowledgments*`). The gate refuses on it
 *   only when *every* one of those organizations has the module inactive, which
 *   is the one case where the endpoint is genuinely absent for that person. A
 *   caller who belongs to one organization that runs it still gets the read —
 *   narrowing what that read then carries, so an organization with the module
 *   off contributes no rows to it, is MOD-019's aggregator rule and is M19.18's
 *   work, not the gate's. Refusing the whole read here instead would take the
 *   surface away from the organization that does run the module.
 */
class RequestOrganizationScope
{
    /**
     * Request body and query keys that name something belonging to an
     * organization, and the models they name.
     *
     * A key may name more than one kind of thing: `document_id` is a policy
     * document on one command and a procedure document on the next, and
     * `scope_id` is whichever of organization, department, or team the document
     * or waiver being written is scoped to. Every candidate is tried and an id
     * that matches no row contributes nothing, so an ambiguous key costs a miss
     * rather than a wrong answer.
     *
     * `staff_id` is absent on purpose. A staff member holds a status in more
     * than one organization, so it names a person rather than a scope, and
     * every command that carries one carries a scope beside it.
     *
     * @var array<string, list<class-string<Model>>>
     */
    private const SUBJECT_KEYS = [
        'organization_id' => [Organization::class],
        'event_id' => [Event::class],
        'department_id' => [Department::class],
        'team_id' => [Team::class],
        'scope_id' => [Organization::class, Department::class, Team::class],
        'shift_id' => [Shift::class],
        'incident_id' => [Incident::class],
        'target_incident_id' => [Incident::class],
        'incident_type_id' => [IncidentType::class],
        'timeline_entry_id' => [IncidentTimelineEntry::class],
        'preset_id' => [IncidentListPreset::class],
        'field_report_id' => [FieldReport::class],
        'attachment_id' => [Attachment::class],
        'document_id' => [PolicyDocument::class, ProcedureDocument::class],
        'fragment_id' => [DocumentFragment::class],
        'requirement_id' => [DocumentAcknowledgmentRequirement::class],
        'waiver_id' => [Waiver::class],
        'training_id' => [Training::class],
        'prerequisite_training_id' => [Training::class],
        'equipment_item_id' => [EquipmentItem::class],
        'equipment_checkout_id' => [EquipmentCheckout::class],
        'deployment_id' => [Deployment::class],
    ];

    /**
     * Relations walked, in order, to get from a record to its organization.
     *
     * Most of Meridian's records carry `organization_id` and never reach this.
     * The ones that do not are the ones scoped to something that is itself
     * scoped — a shift belongs to a department, an incident to an event, a
     * Field Report photo to the report it was taken for — and one of these
     * names the next step up in every case. First hit wins, so the shortest
     * path to an organization is the one taken.
     *
     * @var list<string>
     */
    private const OWNER_RELATIONS = [
        'organization',
        'event',
        'department',
        'incident',
        'fieldReport',
        'attachable',
        'shift',
        'team',
        'training',
    ];

    /**
     * How far the walk above may go. Three steps reaches the deepest record
     * Meridian has — a Field Report photo, which is an attachment on a report
     * in an event in an organization — and stops a relation cycle from turning
     * a gate into a loop.
     */
    private const MAX_DEPTH = 3;

    public function __construct(private readonly OrganizationHostContext $host) {}

    /**
     * The organizations this request names.
     *
     * @return list<string>
     */
    public function named(Request $request): array
    {
        $ids = [];

        $hostOrganization = $this->host->organization();

        if ($hostOrganization !== null) {
            $ids[] = (string) $hostOrganization->getKey();
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                $id = $this->organizationIdOf($parameter);

                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        foreach (self::SUBJECT_KEYS as $key => $models) {
            $value = $request->input($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            foreach ($models as $model) {
                $record = $model::query()->find($value);

                if ($record === null) {
                    continue;
                }

                $id = $this->organizationIdOf($record);

                if ($id !== null) {
                    $ids[] = $id;

                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The organizations the caller holds a staff status in.
     *
     * Every status, whatever it says. A prospective or suspended member is
     * still a member of that organization for the purpose of this question,
     * because what is being asked is which organizations' module state could
     * possibly bear on the request — not what the caller may do, which is the
     * permission check's job and runs after this one (technical spec 15A.4).
     *
     * @return list<string>
     */
    public function membership(?Authenticatable $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $staffIds = $user->staffProfiles->modelKeys();

        if ($staffIds === []) {
            return [];
        }

        return StaffOrganizationStatus::query()
            ->whereIn('staff_id', $staffIds)
            ->distinct()
            ->pluck('organization_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * The organization a record belongs to, or null when nothing here reaches
     * one.
     */
    private function organizationIdOf(Model $record, int $depth = 0): ?string
    {
        if ($record instanceof Organization) {
            return (string) $record->getKey();
        }

        $direct = $record->getAttribute('organization_id');

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        foreach (self::OWNER_RELATIONS as $relation) {
            if (! method_exists($record, $relation)) {
                continue;
            }

            try {
                $owner = $record->getAttribute($relation);
            } catch (Throwable) {
                // The method is not a relation. Eloquent says so by throwing,
                // and a record that answers a name for some other reason is
                // simply not a step on this path.
                continue;
            }

            if (! $owner instanceof Model) {
                continue;
            }

            $id = $this->organizationIdOf($owner, $depth + 1);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }
}
