<?php

namespace App\Services\FieldReports;

use App\Exceptions\FieldReportAcceptanceException;

/**
 * Field Report title validation (FR-003; technical spec 17.3; data/API 10.15).
 *
 * Titles are required plain text: outer whitespace trimmed, 1–200 characters
 * after trimming. Duplicate titles are allowed within an event.
 */
final class FieldReportTitle
{
    public const MAX_LENGTH = 200;

    public static function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            throw FieldReportAcceptanceException::invalid('Field Report title is required.');
        }

        $title = trim($value);

        if ($title === '') {
            throw FieldReportAcceptanceException::invalid('Field Report title is required.');
        }

        if (mb_strlen($title) > self::MAX_LENGTH) {
            throw FieldReportAcceptanceException::invalid(
                'Field Report title must be at most '.self::MAX_LENGTH.' characters.',
            );
        }

        return $title;
    }
}
