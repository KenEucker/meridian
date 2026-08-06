<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\SharedWorkstation;
use App\Models\User;
use App\Services\Auth\SharedWorkstationLoginCodeService;
use App\Services\Auth\SharedWorkstationLoginException;
use App\Services\Kiosk\KioskPinnedContextException;
use App\Services\Kiosk\KioskPinnedContextService;
use App\Services\Node\NodeSetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Provision a trusted shared workstation on this node, pinned and ready to sign
 * in to (M18.32; technical spec 13.1, 13.2).
 *
 * Development and QA tooling. Everything it does is something God Mode already
 * does through a console screen — register a trusted device, pin a Kiosk
 * context, issue login codes — and it does it through the same services, so the
 * same rules and the same audit entries apply. What it adds is that a developer
 * can get from a seeded database to a working Kiosk in one command instead of
 * three screens and a `tinker` session.
 *
 * Two guards, and both matter for a command that creates a *trusted* device.
 *
 *  1. **It refuses outside a local environment** unless `--force` is passed.
 *     Trusting a workstation is a security decision; it should not be one a
 *     stray command in a deployment pipeline can make.
 *  2. **It reuses by name.** Running it twice pins the same workstation again
 *     rather than accumulating trusted machines nobody registered on purpose.
 *
 * Login codes are printed, once, exactly as the God Mode screen shows them
 * once. Technical spec 13.2 rules out printing codes as event prep sheets, and
 * this is not that: it is the local operator issuing a credential to themselves
 * at the machine in front of them, which is the same act the console performs.
 */
class ProvisionSharedWorkstationCommand extends Command
{
    protected $signature = 'meridian:shared-workstation
        {--name=onsite-command-1 : The workstation name, reused when it already exists}
        {--event= : Event id or name to pin to; defaults to the most recent event}
        {--department= : Department id or name to pin to; defaults to the whole site}
        {--code-for=* : Email of a user to issue a login code for; repeatable}
        {--codes=2 : How many login codes to issue when no --code-for is given}
        {--json : Emit the result as JSON for a launcher to read}
        {--force : Provision outside a local environment}';

    protected $description = 'Provision a trusted, pinned shared workstation and issue login codes for it';

    public function handle(
        KioskPinnedContextService $pinnedContext,
        SharedWorkstationLoginCodeService $loginCodes,
    ): int {
        if (! app()->environment('local', 'testing') && ! $this->option('force')) {
            $this->error('Refusing to trust a workstation outside a local environment. Pass --force if that is really what you want.');

            return self::FAILURE;
        }

        $event = $this->resolveEvent();

        if (! $event instanceof Event) {
            // `resolveEvent()` has already said which of the two happened when
            // it was a locked-node conflict rather than an empty database.
            if ($this->option('event') === null || trim((string) $this->option('event')) === '') {
                $this->error('No event to pin to. Seed the database first: pnpm run server:migrate:seed');
            }

            return self::FAILURE;
        }

        $department = $this->resolveDepartment($event);

        if ($department === false) {
            return self::FAILURE;
        }

        // The operator of record. Nothing here is unattributed: a pin and a code
        // are both audited, and an audit entry with no actor is not one.
        $operator = $this->resolveOperator();

        if (! $operator instanceof User) {
            $this->error('No user on this node to act as the operator. Seed the database first.');

            return self::FAILURE;
        }

        $workstation = $this->resolveWorkstation((string) $this->option('name'));

        try {
            $workstation = $pinnedContext->pin(
                workstation: $workstation,
                event: $event,
                department: $department,
                actor: $operator,
                organization: $event->organization()->first(),
            );
        } catch (KioskPinnedContextException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $codes = $this->issueCodes($loginCodes, $workstation, $operator);

        return $this->option('json')
            ? $this->emitJson($workstation, $event, $department, $codes)
            : $this->emitReport($workstation, $event, $department, $codes);
    }

    /**
     * The workstation to pin, created with a trusted device behind it when this
     * node has never had one by that name.
     *
     * A shared workstation is a kind of trusted device (technical spec 13.1),
     * so there is always a `devices` row; in a real deployment that row arrives
     * through node pairing, and here it is created alongside because a developer
     * pairing a device to reach a test kiosk is ceremony with nothing behind it.
     */
    private function resolveWorkstation(string $name): SharedWorkstation
    {
        $existing = SharedWorkstation::query()->where('name', $name)->first();

        if ($existing instanceof SharedWorkstation) {
            // A workstation that was revoked or untrusted is put back into
            // service rather than left as a machine the node will not answer
            // for, which is the state a developer would otherwise have to go
            // and repair by hand.
            $existing->forceFill(['trusted' => true, 'revoked_at' => null])->save();

            return $existing;
        }

        $device = Device::query()->create([
            'device_label' => $name,
            'platform' => 'electron',
            'device_public_key' => base64_encode(random_bytes(32)),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return SharedWorkstation::query()->create([
            'device_id' => $device->getKey(),
            'name' => $name,
            'trusted' => true,
        ]);
    }

    /**
     * The event to pin to, or null when there is none to pin to.
     *
     * **A locked node decides.** A node locked to an event holds that event's
     * records and no others, and `SessionResolver` refuses to resolve a session
     * at any other one — so a workstation on that node pinned elsewhere produces
     * a Kiosk that signs somebody in and then cannot resolve who they are. The
     * lock is not a preference here, it is the only answer that works, which is
     * why a `--event` naming a different one is refused rather than honoured.
     *
     * With no lock, the most recent event, which is the one a developer seeding a
     * database is almost always looking at.
     */
    private function resolveEvent(): ?Event
    {
        $locked = $this->lockedEvent();
        $named = trim((string) $this->option('event'));

        if ($named === '') {
            return $locked ?? Event::query()
                ->whereNull('archived_at')
                ->orderByDesc('starts_at')
                ->orderByDesc('created_at')
                ->first();
        }

        $requested = Event::query()
            ->where(fn ($query) => $query
                ->where('id', $named)
                ->orWhere('name', $named)
                ->orWhere('slug', $named))
            ->first();

        if ($locked instanceof Event && $requested instanceof Event && ! $locked->is($requested)) {
            $this->error("This node is locked to \"{$locked->name}\", so a workstation on it cannot be pinned to another event.");

            return null;
        }

        return $requested;
    }

    private function lockedEvent(): ?Event
    {
        $node = app(NodeSetupService::class)->activeNode();

        return $node instanceof Node && $node->event_id !== null
            ? Event::query()->find($node->event_id)
            : null;
    }

    /**
     * The department to pin, null for the whole site, or false when the named
     * one could not be resolved.
     *
     * A named department that does not exist is an error rather than a silent
     * fall back to the whole site: somebody who typed a department wants that
     * department, and a Kiosk pinned to the wrong scope is worse than one that
     * refused to start.
     */
    private function resolveDepartment(Event $event): Department|null|false
    {
        $named = trim((string) $this->option('department'));

        if ($named === '') {
            return null;
        }

        $department = Department::query()
            ->where('organization_id', $event->organization_id)
            ->where(fn ($query) => $query->where('id', $named)->orWhere('name', $named))
            ->first();

        if (! $department instanceof Department) {
            $this->error("No department named \"{$named}\" in this event's organization.");

            return false;
        }

        return $department;
    }

    private function resolveOperator(): ?User
    {
        return User::query()->orderBy('created_at')->first();
    }

    /**
     * Issue a code per user, so switching users at the workstation can actually
     * be exercised — one code signs one person in, and a handover needs two.
     *
     * @return Collection<int, array{name: string, email: string, code: string}>
     */
    private function issueCodes(
        SharedWorkstationLoginCodeService $loginCodes,
        SharedWorkstation $workstation,
        User $operator,
    ): Collection {
        $issued = collect();

        foreach ($this->resolveCodeSubjects() as $subject) {
            try {
                $code = $loginCodes->generateForUser($subject, $operator, $workstation);
            } catch (SharedWorkstationLoginException $exception) {
                $this->warn("No code for {$subject->email}: {$exception->getMessage()}");

                continue;
            }

            $issued->push([
                'name' => (string) $subject->name,
                'email' => (string) $subject->email,
                'code' => $code->formattedCode(),
            ]);
        }

        return $issued;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveCodeSubjects(): Collection
    {
        /** @var list<string> $emails */
        $emails = array_values(array_filter(array_map(
            'trim',
            (array) $this->option('code-for'),
        )));

        if ($emails !== []) {
            return collect($emails)
                ->map(function (string $email): ?User {
                    $user = User::query()->where('email', $email)->first();

                    if (! $user instanceof User) {
                        $this->warn("No user with the email {$email} on this node.");
                    }

                    return $user;
                })
                ->filter()
                ->values();
        }

        $count = max(0, (int) $this->option('codes'));

        return User::query()->orderBy('created_at')->limit($count)->get();
    }

    /**
     * @param  Collection<int, array{name: string, email: string, code: string}>  $codes
     */
    private function emitJson(
        SharedWorkstation $workstation,
        Event $event,
        ?Department $department,
        Collection $codes,
    ): int {
        $this->line((string) json_encode([
            'shared_workstation_id' => (string) $workstation->getKey(),
            'shared_workstation_name' => (string) $workstation->name,
            'organization' => (string) ($event->organization()->first()?->name ?? ''),
            'event' => (string) $event->name,
            'department' => $department?->name,
            'login_codes' => $codes->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{name: string, email: string, code: string}>  $codes
     */
    private function emitReport(
        SharedWorkstation $workstation,
        Event $event,
        ?Department $department,
        Collection $codes,
    ): int {
        $this->info("Shared workstation \"{$workstation->name}\" is trusted and pinned.");
        $this->newLine();

        $this->table(['', ''], [
            ['Workstation id', (string) $workstation->getKey()],
            ['Organization', (string) ($event->organization()->first()?->name ?? 'Unknown')],
            ['Event', (string) $event->name],
            ['Department', $department?->name ?? 'The whole site'],
        ]);

        if ($codes->isEmpty()) {
            $this->warn('No login codes were issued, so nobody can sign in at this workstation yet.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Login codes — shown once, exactly as the God Mode screen shows them:');
        $this->table(
            ['User', 'Email', 'Code'],
            $codes->map(fn (array $code): array => [$code['name'], $code['email'], $code['code']])->all(),
        );

        return self::SUCCESS;
    }
}
