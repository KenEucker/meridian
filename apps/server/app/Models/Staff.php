<?php

namespace App\Models;

use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Screen\AsSource;

class Staff extends Model
{
    use AsSource;
    use Filterable;

    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legal_name',
        'preferred_name',
        'handle',
        'formerly_known_as',
        'email',
        'phone',
        'city',
        'state',
        'date_of_birth',
        'emergency_contact_name',
        'emergency_contact_phone',
        'profile_picture_path',
        'profile_picture_mime_type',
        'profile_picture_size_bytes',
        'profile_picture_width',
        'profile_picture_height',
        'profile_picture_uploaded_at',
        'archived_at',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'legal_name' => Like::class,
        'preferred_name' => Like::class,
        'handle' => Like::class,
        'email' => Like::class,
        'city' => Like::class,
        'state' => Like::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'id',
        'legal_name',
        'preferred_name',
        'handle',
        'email',
        'city',
        'state',
        'updated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'profile_picture_uploaded_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_user')->withTimestamps();
    }

    public function organizationStatuses(): HasMany
    {
        return $this->hasMany(StaffOrganizationStatus::class);
    }

    public function departmentMemberships(): HasMany
    {
        return $this->hasMany(DepartmentMembership::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'staff_organization_statuses')
            ->withPivot([
                'status',
                'status_reason',
                'status_changed_at',
                'status_changed_by_user_id',
            ])
            ->withTimestamps();
    }

    /**
     * @param  Builder<Staff>  $query
     * @return Builder<Staff>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function profilePictureUrl(): ?string
    {
        if ($this->profile_picture_path === null || $this->profile_picture_path === '') {
            return null;
        }

        if (str_starts_with($this->profile_picture_path, '/') || filter_var($this->profile_picture_path, FILTER_VALIDATE_URL) !== false) {
            return $this->profile_picture_path;
        }

        return Storage::disk('public')->url($this->profile_picture_path);
    }
}
