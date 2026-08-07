<?php

declare(strict_types=1);

namespace App\Services\Offline\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How every contributor turns records into rows.
 *
 * Shared rather than repeated because the offline read set is one document a
 * client parses with one set of expectations. Two contributors formatting a
 * timestamp differently would be a difference no test names and every device
 * has to cope with.
 */
trait ShapesOfflineRows
{
    /**
     * @template TModel of Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function rows($query, callable $row): array
    {
        return $this->map($query->get(), $row);
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  callable(TModel): array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function map(Collection $models, callable $row): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $models->map($row)->values()->all();

        return $rows;
    }

    /**
     * A timestamp as the device reads it, or null.
     *
     * Every moment in the set is ISO 8601 with its offset. A device compares
     * these against its own clock while it has no way to ask the node what time
     * it is, and a bare local-looking string would be the wrong moment on a
     * device whose timezone is not the event's.
     */
    private function moment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return (string) $value;
    }

    /**
     * The same rows a second contributor would produce for the same records,
     * de-duplicated by id and ordered so two composals of an unchanged set agree.
     *
     * A role-additive list reaches the same record by more than one route — two
     * departments of one event, an unscoped grant and an event-scoped one — and
     * the version is a hash of the rows, so a set whose row order depended on
     * which grant was read first would answer a different version every time and
     * cost the weakest connection a full transfer.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function distinct(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[(string) ($row['id'] ?? count($byId))] = $row;
        }

        ksort($byId);

        return array_values($byId);
    }
}
