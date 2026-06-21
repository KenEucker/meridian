<?php

namespace App\Models;

use Database\Factories\ShiftWaiverRequirementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftWaiverRequirement extends Model
{
    /** @use HasFactory<ShiftWaiverRequirementFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'waiver_id',
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }
}
