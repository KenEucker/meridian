<?php

namespace Database\Seeders\Support;

use App\Models\Department;
use App\Models\Device;
use App\Models\Event;
use App\Models\Node;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;

/**
 * Everything the operational seeders look up, resolved once.
 *
 * The scenario is built by several seeders that each own one feature area, and
 * all of them need the same handful of rows: the organization, the two events,
 * the departments and teams, and a persona addressed by the key the catalog
 * gave it. Without this each seeder would open with thirty lines of `firstOrFail`
 * and would be free to disagree with its neighbours about which "Rangers" it
 * meant.
 *
 * Resolution is by the natural keys the topology seeder wrote — slug, code,
 * email — never by id, because ids differ between one developer's database and
 * the next and a seeder that hard-codes one is a seeder that only works on the
 * machine it was written on.
 */
final class ScenarioContext
{
    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, Team> */
    private array $teams = [];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Staff> */
    private array $staff = [];

    private ?Organization $organization = null;

    private ?Event $event = null;

    private ?Event $upcomingEvent = null;

    private ?Node $node = null;

    private ?Device $device = null;

    public function organization(): Organization
    {
        return $this->organization ??= Organization::query()
            ->where('slug', DevelopmentScenarioCatalog::ORGANIZATION_SLUG)
            ->firstOrFail();
    }

    /** The event running right now. */
    public function event(): Event
    {
        return $this->event ??= Event::query()
            ->where('organization_id', $this->organization()->id)
            ->where('slug', DevelopmentScenarioCatalog::EVENT_SLUG)
            ->firstOrFail();
    }

    /** The event still ahead, outside its active window. */
    public function upcomingEvent(): Event
    {
        return $this->upcomingEvent ??= Event::query()
            ->where('organization_id', $this->organization()->id)
            ->where('slug', DevelopmentScenarioCatalog::UPCOMING_EVENT_SLUG)
            ->firstOrFail();
    }

    public function department(string $code): Department
    {
        return $this->departments[$code] ??= Department::query()
            ->where('organization_id', $this->organization()->id)
            ->where('code', $code)
            ->firstOrFail();
    }

    public function team(string $departmentCode, string $teamCode): Team
    {
        $key = $departmentCode.':'.$teamCode;

        return $this->teams[$key] ??= Team::query()
            ->where('department_id', $this->department($departmentCode)->id)
            ->where('code', $teamCode)
            ->firstOrFail();
    }

    /** The signed-in account for a persona key from the catalog. */
    public function user(string $personaKey): User
    {
        return $this->users[$personaKey] ??= User::query()
            ->where('email', $this->emailFor($personaKey))
            ->firstOrFail();
    }

    /** The staff record a persona is operated on as. */
    public function staff(string $personaKey): Staff
    {
        return $this->staff[$personaKey] ??= Staff::query()
            ->where('email', $this->emailFor($personaKey))
            ->firstOrFail();
    }

    /**
     * @param  list<string>  $personaKeys
     * @return list<Staff>
     */
    public function staffAll(array $personaKeys): array
    {
        return array_map(fn (string $key): Staff => $this->staff($key), $personaKeys);
    }

    /**
     * This install's local node.
     *
     * Field Reports and document acknowledgments both record the node that
     * accepted them and refuse without one, so the scenario cannot be built at
     * all until a node exists. Created here rather than in a seeder of its own
     * because everything downstream needs it and nothing configures it.
     */
    public function node(): Node
    {
        return $this->node ??= Node::query()->firstOrCreate(
            ['node_name' => 'Northwood Development Node'],
            [
                'node_role' => Node::ROLE_DEVELOPMENT,
                'is_local' => true,
                'organization_id' => $this->organization()->id,
                'event_id' => $this->event()->id,
                // Development key material, and deliberately fixed rather than
                // random: nothing verifies a signature against it in this
                // scenario, and a value that changes on every seed makes two
                // developers' databases differ for no reason anybody can act on.
                'public_key' => base64_encode(str_repeat('northwood-node', 3)),
                'paired_at' => ScenarioClock::daysAgo(30),
            ],
        );
    }

    /** A registered device, for operations that record where they came from. */
    public function device(): Device
    {
        return $this->device ??= Device::query()->firstOrCreate(
            ['device_label' => 'Northwood Desk Tablet'],
            [
                'platform' => 'web',
                'device_public_key' => base64_encode(str_repeat('northwood-device', 2)),
                'first_seen_at' => ScenarioClock::daysAgo(30),
                'last_seen_at' => ScenarioClock::now(),
            ],
        );
    }

    private function emailFor(string $personaKey): string
    {
        foreach (DevelopmentScenarioCatalog::personas() as $persona) {
            if ($persona['key'] === $personaKey) {
                return $persona['email'];
            }
        }

        throw new \InvalidArgumentException("No development persona is registered under the key `{$personaKey}`.");
    }
}
