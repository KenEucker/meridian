<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\FieldReport;
use App\Models\FieldReportAppend;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldReportAppend>
 */
class FieldReportAppendFactory extends Factory
{
    protected $model = FieldReportAppend::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'field_report_id' => FieldReport::factory(),
            'appended_by_user_id' => User::factory(),
            'body' => $this->faker->paragraph(),
            'device_submitted_at' => now(),
            'server_received_at' => null,
            'origin_device_id' => Device::factory(),
            'origin_node_id' => Node::factory(),
        ];
    }

    public function forReport(FieldReport $report): static
    {
        return $this->state(fn (): array => [
            'field_report_id' => $report->id,
            'appended_by_user_id' => $report->submitted_by_user_id,
        ]);
    }

    public function receivedByServer(?\DateTimeInterface $receivedAt = null): static
    {
        return $this->state(fn (): array => [
            'server_received_at' => $receivedAt ?? now(),
        ]);
    }
}
