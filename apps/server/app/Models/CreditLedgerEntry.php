<?php

namespace App\Models;

use Database\Factories\CreditLedgerEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One calculated credit result for one hours record (CREDIT-001 through
 * CREDIT-005; data/API section 10.12).
 *
 * Entries freeze at calculation. CREDIT-001 only lets credits be calculated
 * once the correction grace period has closed on the hours behind them, so
 * there is no state in which an entry is calculated but still open — the number
 * it carries is already final when the row is written, and the model refuses
 * updates and deletes of a frozen row so a later policy edit cannot reprice
 * finished work (CREDIT-004).
 */
class CreditLedgerEntry extends Model
{
    /** @use HasFactory<CreditLedgerEntryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'credit_ledger_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Ledger entries only record a creation timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * Credits derived from a recorded hours record. Manual adjustment entries
     * are a separate entry type with their own authority path and are not part
     * of Alpha 1 credit calculation.
     */
    public const ENTRY_TYPE_CALCULATED = 'calculated';

    /**
     * The only state an entry is written in. Calculation and freeze are the
     * same moment (CREDIT-001, CREDIT-004).
     */
    public const STATUS_FROZEN = 'frozen';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'department_id',
        'shift_id',
        'staff_id',
        'hours_worked_id',
        'credit_policy_id',
        'entry_type',
        'hours',
        'credits',
        'status',
        'calculation_basis',
        'created_by_user_id',
        'frozen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'credits' => 'decimal:2',
            'calculation_basis' => 'array',
            'created_at' => 'datetime',
            'frozen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CreditLedgerEntry $entry): void {
            if ($entry->getOriginal('frozen_at') !== null) {
                throw new RuntimeException('Frozen credit ledger entries cannot be updated.');
            }
        });

        static::deleting(function (CreditLedgerEntry $entry): void {
            if ($entry->getOriginal('frozen_at') !== null) {
                throw new RuntimeException('Frozen credit ledger entries cannot be deleted.');
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function hoursWorked(): BelongsTo
    {
        return $this->belongsTo(HoursWorked::class, 'hours_worked_id');
    }

    public function creditPolicy(): BelongsTo
    {
        return $this->belongsTo(CreditPolicy::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  Builder<CreditLedgerEntry>  $query
     * @return Builder<CreditLedgerEntry>
     */
    public function scopeCalculated(Builder $query): Builder
    {
        return $query->where('entry_type', self::ENTRY_TYPE_CALCULATED);
    }

    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }
}
