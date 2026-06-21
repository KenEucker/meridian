<?php

namespace App\Models;

use Database\Factories\TrainingPrerequisiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingPrerequisite extends Model
{
    /** @use HasFactory<TrainingPrerequisiteFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'training_id',
        'prerequisite_training_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function prerequisiteTraining(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'prerequisite_training_id');
    }
}
