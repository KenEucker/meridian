<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Device;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();
        $filename = sprintf('EVENT-2027_FRA-2027-000001_2027-07-04T13-22-10Z_01.webp');

        return [
            'id' => $id,
            'attachable_type' => Attachment::MORPH_FIELD_REPORT,
            'attachable_id' => FieldReport::factory(),
            'uploaded_by_user_id' => User::factory(),
            'filename' => $filename,
            'mime_type' => 'image/webp',
            'byte_size' => 128,
            'storage_disk' => 'attachments',
            'storage_path' => 'field-reports/'.$filename,
            'checksum' => hash('sha256', 'factory-attachment-bytes'),
            'metadata_json' => [
                'width' => 10,
                'height' => 10,
                'slot' => 1,
            ],
            'origin_device_id' => Device::factory(),
            'origin_node_id' => Node::factory(),
            'created_at' => now(),
            'stricken_at' => null,
            'deleted_at' => null,
        ];
    }

    public function forFieldReport(FieldReport $report): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => Attachment::MORPH_FIELD_REPORT,
            'attachable_id' => $report->id,
            'uploaded_by_user_id' => $report->submitted_by_user_id,
            'origin_device_id' => $report->origin_device_id,
            'origin_node_id' => $report->origin_node_id,
        ]);
    }

    public function forIncident(Incident $incident, ?User $uploadedBy = null): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => Attachment::MORPH_INCIDENT,
            'attachable_id' => $incident->id,
            'uploaded_by_user_id' => $uploadedBy?->id ?? User::factory(),
            'filename' => 'INC-2027-000001_2027-07-04T13-22-10Z_01.webp',
            'storage_path' => 'incidents/INC-2027-000001_2027-07-04T13-22-10Z_01.webp',
        ]);
    }
}
