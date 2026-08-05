<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Device;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Node;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldReport>
 */
class FieldReportFactory extends Factory
{
    protected $model = FieldReport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'department_id' => null,
            'team_id' => null,
            'submitted_by_user_id' => User::factory(),
            'staff_id' => Staff::factory(),
            'fra_number' => null,
            'temporary_local_number' => null,
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'device_submitted_at' => now(),
            'server_received_at' => null,
            'origin_device_id' => Device::factory(),
            'origin_node_id' => Node::factory(),
            // Vocabulary for sync_status is owned by offline/sync tasks (M9.2+).
            'sync_status' => 'pending_sync',
        ];
    }

    public function forEvent(Event $event): static
    {
        return $this->state(fn (): array => [
            'event_id' => $event->id,
        ]);
    }

    /**
     * A report this user both authored and submitted.
     *
     * The staff record is linked to the user, because that link is what makes
     * them the author (FR-009, FR-015, FR-016). An unlinked staff record would
     * describe a report *taken* for somebody else, which is a different thing
     * and has its own state below.
     */
    public function forAuthor(User $user, ?Staff $staff = null): static
    {
        return $this
            ->state(fn (): array => [
                'submitted_by_user_id' => $user->id,
                'staff_id' => $staff?->id ?? Staff::factory(),
            ])
            ->afterCreating(function (FieldReport $report) use ($user): void {
                $author = Staff::query()->find($report->staff_id);

                if ($author !== null && ! $author->users()->whereKey($user->getKey())->exists()) {
                    $author->users()->attach($user->getKey());
                }
            });
    }

    /**
     * A report an operator took for somebody else (FR-015).
     *
     * `$author` is the reporting staff member the report belongs to and
     * `$submitter` is the operator who wrote it down. The two are deliberately
     * left unlinked: that is the whole shape of a taken report, and it is what
     * append authority is measured against (FR-016).
     */
    public function takenOnBehalf(Staff $author, User $submitter): static
    {
        return $this->state(fn (): array => [
            'staff_id' => $author->id,
            'submitted_by_user_id' => $submitter->id,
        ]);
    }

    public function withDepartmentContext(Department $department, ?Team $team = null): static
    {
        return $this->state(fn (): array => [
            'department_id' => $department->id,
            'team_id' => $team?->id ?? $department->default_team_id,
        ]);
    }

    public function receivedByServer(?\DateTimeInterface $receivedAt = null): static
    {
        return $this->state(fn (): array => [
            'server_received_at' => $receivedAt ?? now(),
            'sync_status' => 'accepted',
        ]);
    }
}
