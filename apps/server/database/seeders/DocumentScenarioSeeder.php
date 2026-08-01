<?php

namespace Database\Seeders;

use App\Models\DocumentAcknowledgment;
use App\Models\DocumentAcknowledgmentRequirement;
use App\Models\DocumentFragment;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\User;
use App\Services\Documents\DocumentAcknowledgmentRequirementService;
use App\Services\Documents\DocumentAcknowledgmentService;
use App\Services\Documents\DocumentAdminService;
use App\Services\Documents\DocumentFragmentAdminService;
use Database\Seeders\Support\ScenarioContext;
use Illuminate\Database\Seeder;

/**
 * Policies, procedures, fragments, and the acknowledgments hanging off them.
 *
 * Four things have to be true at once for these surfaces to be worth opening,
 * and the library is built so that all four are. There is a document in each
 * state, because the state filter is the first control on the page and a filter
 * with one answer is not a filter. There is a document at more than one scope,
 * because who may maintain what is resolved per scope and a library that is
 * entirely organization-scoped never exercises that. There is a fragment two
 * documents both include, because a fragment nothing references cannot show a
 * version bump propagating, which is the whole reason fragments exist. And the
 * Event Info sections are filled from real published documents rather than left
 * empty, so the staff-facing page shows content in some sections and its
 * documented empty wording in the rest — which is the comparison that proves
 * the empty state is deliberate rather than broken.
 */
class DocumentScenarioSeeder extends Seeder
{
    /**
     * Authored before the gates open, which is why nothing here is frozen.
     *
     * Governance edits are refused organization-wide while any event is inside
     * its active window, and the seeded event ends up inside one. This seeder
     * runs before {@see OpenEventWindowSeeder} opens it, so the library is
     * written the way an organization really writes it — in the weeks before the
     * event — rather than by standing the freeze down and producing rows the
     * product itself could never have produced.
     */
    public function run(): void
    {
        $context = new ScenarioContext;
        $organization = $context->organization();
        $rangers = $context->department('RANGERS');
        $olive = $context->user('olive');
        $dana = $context->user('dana');

        $documents = app(DocumentAdminService::class);
        $fragments = app(DocumentFragmentAdminService::class);

        // Shared boilerplate, included by both the arrival policy and the radio
        // procedure below.
        $contact = $this->fragment($fragments, $context, $olive, [
            'name' => 'Ranger HQ Contact Block',
            'slug' => 'ranger-hq-contact',
            'markdown_source' => "**Ranger HQ** is at centre camp, north side.\n\nRadio channel 3, or walk up any time.",
        ]);

        $this->fragment($fragments, $context, $olive, [
            'name' => 'Quiet Hours Notice',
            'slug' => 'quiet-hours-notice',
            'markdown_source' => 'Amplified sound is off between 4am and 9am in the residential ring.',
        ]);

        // Published, organization scope, and placed in an Event Info section so
        // the staff-facing page has content rather than six empty panels.
        $this->document($documents, new PolicyDocument, $context, $olive, [
            'organization_id' => (string) $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => (string) $organization->id,
            'title' => 'Arrival and Gate Policy',
            'slug' => 'arrival-and-gate-policy',
            'event_info_section' => 'arrival',
            'state' => PolicyDocument::STATE_PUBLISHED,
            'markdown_source' => "All staff check in at the gate before reporting to their department.\n\n"
                ."Bring photo identification and your confirmation email.\n\n"
                .'{{fragment:'.$contact->slug."}}\n",
        ]);

        $this->document($documents, new PolicyDocument, $context, $olive, [
            'organization_id' => (string) $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => (string) $organization->id,
            'title' => 'What To Bring',
            'slug' => 'what-to-bring',
            'event_info_section' => 'packing',
            'state' => PolicyDocument::STATE_PUBLISHED,
            'markdown_source' => "Water for the whole event, closed-toe boots, warm layers, dust protection, and a headlamp.\n",
        ]);

        // Draft, so the state filter has both sides and an editor has something
        // unpublished to publish.
        $this->document($documents, new PolicyDocument, $context, $olive, [
            'organization_id' => (string) $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => (string) $organization->id,
            'title' => 'Housing and Camping Policy',
            'slug' => 'housing-and-camping-policy',
            'event_info_section' => 'housing',
            'state' => PolicyDocument::STATE_DRAFT,
            'markdown_source' => "Draft. Placement is still being worked out with the camps.\n",
        ]);

        // Archived, so that filter is not empty either.
        $this->document($documents, new PolicyDocument, $context, $olive, [
            'organization_id' => (string) $organization->id,
            'scope_type' => PolicyDocument::SCOPE_ORGANIZATION,
            'scope_id' => (string) $organization->id,
            'title' => 'Legacy Gate Policy',
            'slug' => 'legacy-gate-policy',
            'state' => PolicyDocument::STATE_ARCHIVED,
            'markdown_source' => "Superseded by the Arrival and Gate Policy.\n",
        ]);

        // Department scope, maintained by the department rather than by
        // organizers, which is the case that proves scope means something.
        $this->document($documents, new PolicyDocument, $context, $dana, [
            'organization_id' => (string) $organization->id,
            'scope_type' => PolicyDocument::SCOPE_DEPARTMENT,
            'scope_id' => (string) $rangers->id,
            'title' => 'Ranger Code of Conduct',
            'slug' => 'ranger-code-of-conduct',
            'state' => PolicyDocument::STATE_PUBLISHED,
            'markdown_source' => "Rangers do not enforce. Rangers mediate, de-escalate, and call for help.\n",
        ]);

        $this->document($documents, new ProcedureDocument, $context, $dana, [
            'organization_id' => (string) $organization->id,
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => (string) $rangers->id,
            'title' => 'Radio Procedure',
            'slug' => 'radio-procedure',
            'state' => ProcedureDocument::STATE_PUBLISHED,
            'markdown_source' => "Call sign, location, nature of the call, in that order.\n\n"
                .'{{fragment:'.$contact->slug."}}\n",
        ]);

        $this->document($documents, new ProcedureDocument, $context, $dana, [
            'organization_id' => (string) $organization->id,
            'scope_type' => ProcedureDocument::SCOPE_DEPARTMENT,
            'scope_id' => (string) $rangers->id,
            'title' => 'Sandstorm Shelter Procedure',
            'slug' => 'sandstorm-shelter-procedure',
            'state' => ProcedureDocument::STATE_DRAFT,
            'markdown_source' => "Draft. Pending review with Command.\n",
        ]);

        $this->seedAcknowledgments($context);
    }

    /**
     * One requirement, half satisfied.
     *
     * Everybody acknowledged is a screen with nothing on it, and nobody
     * acknowledged is a screen that cannot show what a completed one looks like.
     * Half the crew has read the code of conduct and half has not, so the
     * organizer's view has both columns populated and a staff member opening
     * their own list has one outstanding item to act on.
     */
    private function seedAcknowledgments(ScenarioContext $context): void
    {
        $conduct = PolicyDocument::query()
            ->where('organization_id', $context->organization()->id)
            ->where('slug', 'ranger-code-of-conduct')
            ->first();

        if ($conduct === null) {
            return;
        }

        $existing = DocumentAcknowledgmentRequirement::query()
            ->where('organization_id', $context->organization()->id)
            ->where('document_id', $conduct->id)
            ->first();

        $requirement = $existing ?? app(DocumentAcknowledgmentRequirementService::class)->create(
            $context->organization(),
            'policy',
            (string) $conduct->id,
            'department',
            (string) $context->department('RANGERS')->id,
            // Signup or training are the only two contexts an acknowledgment may
            // be attached to (POL-023): it is never a shift or credential gate,
            // and the service refuses anything else outright.
            DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP,
        );

        $acknowledgments = app(DocumentAcknowledgmentService::class);

        foreach (['vera', 'sam'] as $key) {
            $user = $context->user($key);

            // Acknowledgments are recorded against the document and version
            // rather than against the requirement row, which is what lets a
            // requirement be re-scoped without discarding what people already
            // read (POL-025).
            $alreadyRead = DocumentAcknowledgment::query()
                ->where('user_id', $user->id)
                ->where('document_type', $requirement->document_type)
                ->where('document_id', $requirement->document_id)
                ->exists();

            if ($alreadyRead) {
                continue;
            }

            $acknowledgments->acknowledge($requirement, $user, $context->node(), $context->staff($key));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function document(
        DocumentAdminService $documents,
        PolicyDocument|ProcedureDocument $blank,
        ScenarioContext $context,
        User $actor,
        array $attributes,
    ): PolicyDocument|ProcedureDocument {
        $existing = $blank->newQuery()
            ->where('organization_id', $context->organization()->id)
            ->where('slug', $attributes['slug'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $documents->save($blank, $attributes, $actor, 'Development scenario seed.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fragment(
        DocumentFragmentAdminService $fragments,
        ScenarioContext $context,
        User $actor,
        array $attributes,
    ): DocumentFragment {
        $existing = DocumentFragment::query()
            ->where('organization_id', $context->organization()->id)
            ->where('slug', $attributes['slug'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $fragments->save(new DocumentFragment, [
            'organization_id' => (string) $context->organization()->id,
            'scope_type' => DocumentFragment::SCOPE_ORGANIZATION,
            'scope_id' => (string) $context->organization()->id,
            ...$attributes,
        ], $actor);
    }
}
