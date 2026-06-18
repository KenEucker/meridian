<?php

namespace App\Models;

use Database\Factories\NodeConfigValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeConfigValue extends Model
{
    /** @use HasFactory<NodeConfigValueFactory> */
    use HasFactory;

    public const SOURCE_FILE = 'file';

    public const SOURCE_DATABASE = 'database';

    public const SOURCE_RUNTIME = 'runtime';

    /**
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_FILE,
        self::SOURCE_DATABASE,
        self::SOURCE_RUNTIME,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'node_id',
        'key',
        'value_json',
        'source',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_json' => 'array',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::SOURCE_FILE => 'file config',
            self::SOURCE_DATABASE => 'database override',
            self::SOURCE_RUNTIME => 'runtime/default',
            default => $source,
        };
    }
}
