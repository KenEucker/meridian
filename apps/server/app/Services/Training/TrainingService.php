<?php

namespace App\Services\Training;

use App\Models\Node;
use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\TrainingPrerequisite;
use App\Models\User;
use Illuminate\Support\Carbon;

class TrainingService
{
    /**
     * Record a prerequisite relationship between two trainings (TRAIN-004).
     *
     * Guards against self-references, cross-organization prerequisites,
     * duplicates, and prerequisite cycles so the prerequisite graph stays
     * resolvable for later shift-eligibility checks.
     */
    public function addPrerequisite(Training $training, Training $prerequisite): TrainingPrerequisite
    {
        if ((string) $training->id === (string) $prerequisite->id) {
            throw TrainingPrerequisiteException::selfReference();
        }

        if ((string) $training->organization_id !== (string) $prerequisite->organization_id) {
            throw TrainingPrerequisiteException::differentOrganization();
        }

        $alreadyExists = TrainingPrerequisite::query()
            ->where('training_id', $training->id)
            ->where('prerequisite_training_id', $prerequisite->id)
            ->exists();

        if ($alreadyExists) {
            throw TrainingPrerequisiteException::duplicate();
        }

        if ($this->wouldCreateCycle($training, $prerequisite)) {
            throw TrainingPrerequisiteException::cycle();
        }

        return TrainingPrerequisite::query()->create([
            'training_id' => $training->id,
            'prerequisite_training_id' => $prerequisite->id,
        ]);
    }

    /**
     * Record a staff member's training completion (TRAIN-003, TRAIN-005).
     *
     * The completion date is captured and the expiration date is derived from
     * the training's configured expiry window (TRAIN-002). When the training
     * does not expire, the completion has no expiration date.
     */
    public function recordCompletion(
        Training $training,
        Staff $staff,
        ?Carbon $completedAt = null,
        ?User $recordedBy = null,
        ?Node $originNode = null,
    ): TrainingCompletion {
        $completedAt ??= Carbon::now();

        $expiresAt = $training->expires_after_days !== null
            ? $completedAt->copy()->addDays($training->expires_after_days)
            : null;

        return TrainingCompletion::query()->create([
            'training_id' => $training->id,
            'staff_id' => $staff->id,
            'completed_at' => $completedAt,
            'expires_at' => $expiresAt,
            'recorded_by_user_id' => $recordedBy?->id,
            'origin_node_id' => $originNode?->id,
        ]);
    }

    public function isCompleteFor(Training $training, Staff $staff, ?Carbon $moment = null): bool
    {
        return $training->isCompleteFor($staff, $moment);
    }

    /**
     * Determine whether adding $prerequisite to $training would create a cycle.
     *
     * A cycle exists when $training is already reachable by following the
     * prerequisite chain that begins at $prerequisite.
     */
    private function wouldCreateCycle(Training $training, Training $prerequisite): bool
    {
        $targetId = (string) $training->id;
        $visited = [];
        $frontier = [(string) $prerequisite->id];

        while ($frontier !== []) {
            if (in_array($targetId, $frontier, true)) {
                return true;
            }

            foreach ($frontier as $id) {
                $visited[$id] = true;
            }

            $next = TrainingPrerequisite::query()
                ->whereIn('training_id', $frontier)
                ->pluck('prerequisite_training_id')
                ->map(fn ($id): string => (string) $id)
                ->reject(fn (string $id): bool => isset($visited[$id]))
                ->unique()
                ->values()
                ->all();

            $frontier = $next;
        }

        return false;
    }
}
