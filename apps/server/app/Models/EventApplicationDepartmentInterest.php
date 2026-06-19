<?php

namespace App\Models;

use Database\Factories\EventApplicationDepartmentInterestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventApplicationDepartmentInterest extends Model
{
    /** @use HasFactory<EventApplicationDepartmentInterestFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_application_id',
        'department_id',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(EventApplication::class, 'event_application_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
