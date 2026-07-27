<?php

namespace App\Services\Node;

use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\DocumentFragmentVersionBump;
use App\Models\Event;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event as Events;

/**
 * Freezes policy/procedure and fragment edits while an event is in its active
 * window (technical spec 10.2, 21.10).
 *
 * This is not the authority rule. Event authority moves event-scoped writes to
 * the on-site primary node, so the same edit is still possible somewhere; this
 * rule blocks the edit outright. Governance content is prepared and pushed
 * before the event starts, and during the window nobody edits it — not central,
 * not the on-site node that holds authority over everything else. The reason the
 * specification gives is version bumps: a fragment edit mid-event silently
 * raises the version of every published document that references it, and staff
 * acknowledge a document at a version. Freezing the content is how an
 * acknowledgment taken on Tuesday still describes what the reader saw.
 *
 * Which window applies is asked of the organization, not of an event, because
 * documents and fragments are scoped to an organization, department, or team and
 * never carry an `event_id`. An organization running an event freezes its own
 * governance content and nothing else on the install.
 *
 * What it covers, and what it does not:
 *
 *   - {@see FROZEN} is the authored content plus the two derived rows that
 *     record a version moving. Fragment references and fragment version bumps
 *     carry no `organization_id` and reach one through their fragment, the same
 *     way {@see EventScopedWriteGuard} reaches an event through a parent row.
 *   - Acknowledgments are deliberately not frozen. They are collected on-site
 *     during signup or training and sync back to central (technical spec 10.2),
 *     and the version snapshot an acknowledgment preserves is written as part of
 *     accepting it, so refusing either would refuse the acknowledgment itself.
 *   - Acknowledgment requirements are deliberately not frozen either. The
 *     specification freezes document and fragment *edits*; a requirement is the
 *     live signup gate rather than document content, changing one bumps no
 *     version, and an organizer has to be able to lift a requirement that is
 *     blocking signups while the event is running.
 *   - `updating` is listened to alongside `saving` because
 *     `Model::increment()` fires only the former. That is the path the queued
 *     fragment revision bump takes, and a silent version bump mid-event is
 *     precisely what this rule exists to stop. A queued bump whose fragment was
 *     edited just before the window opened therefore fails rather than applying;
 *     it stays in the failed queue and can be replayed after the window closes,
 *     which is the honest outcome for a bump that arrived too late.
 *   - Mass query-builder writes fire no model events and are not covered, for
 *     the same reason they are not covered by {@see EventScopedWriteGuard}.
 *
 * Applying a received node operation stands the guard down through
 * {@see withoutEnforcement()}. A document operation cannot be *created* during
 * the window, since both nodes refuse the edit that would create it, so one
 * arriving mid-window carries a pre-window edit that was queued behind a lost
 * connection. Refusing it would discard content central prepared before the
 * event, which is the opposite of the rule's purpose.
 */
class GovernanceWriteGuard
{
    /**
     * Governance content that may not be written while a window is open.
     *
     * @var list<class-string<Model>>
     */
    private const FROZEN = [
        PolicyDocument::class,
        ProcedureDocument::class,
        DocumentFragment::class,
        DocumentFragmentReference::class,
        DocumentFragmentVersionBump::class,
    ];

    /**
     * Frozen models that reach their organization through their fragment.
     *
     * @var list<class-string<Model>>
     */
    private const FRAGMENT_SCOPED = [
        DocumentFragmentReference::class,
        DocumentFragmentVersionBump::class,
    ];

    private bool $enforcing = true;

    public function __construct(private readonly EventAuthority $authority) {}

    /**
     * Refuse governance writes on this node from here on.
     */
    public function register(): void
    {
        Events::listen(
            ['eloquent.saving: *', 'eloquent.updating: *', 'eloquent.deleting: *'],
            function (string $eventName, array $payload): void {
                $model = $payload[0] ?? null;

                if ($model instanceof Model) {
                    $this->guard($model);
                }
            },
        );
    }

    /**
     * Run a callback with the freeze not enforced, for writes that are not
     * mid-event edits.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutEnforcement(Closure $callback): mixed
    {
        $previous = $this->enforcing;
        $this->enforcing = false;

        try {
            return $callback();
        } finally {
            $this->enforcing = $previous;
        }
    }

    /**
     * @throws EventAuthorityException when governance content is frozen
     */
    public function guard(Model $model): void
    {
        if (! $this->enforcing || ! in_array($model::class, self::FROZEN, true)) {
            return;
        }

        $event = $this->authority->activeEventForOrganization($this->organizationIdFor($model));

        if (! $event instanceof Event) {
            return;
        }

        throw EventAuthorityException::governanceFrozenDuringActiveEvent($event, $this->describe($model));
    }

    private function organizationIdFor(Model $model): ?string
    {
        $organizationId = in_array($model::class, self::FRAGMENT_SCOPED, true)
            ? $this->fragmentOrganizationId($model)
            : $model->getAttribute('organization_id');

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }

    private function fragmentOrganizationId(Model $model): mixed
    {
        $fragmentId = $model->getAttribute('fragment_id');

        if (! is_string($fragmentId) || $fragmentId === '') {
            return null;
        }

        return DocumentFragment::query()->whereKey($fragmentId)->value('organization_id');
    }

    /**
     * A human-readable name for the record, for the refusal message. Model class
     * names are the only names available this deep, so they are converted to
     * words rather than shown as class paths.
     */
    private function describe(Model $model): string
    {
        return str(class_basename($model))->snake(' ')->lower()->toString();
    }
}
