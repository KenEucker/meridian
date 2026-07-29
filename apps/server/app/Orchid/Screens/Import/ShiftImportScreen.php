<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Import;

use App\Models\User;
use App\Orchid\Layouts\Import\ShiftImportLayout;
use App\Services\Imports\ImportResult;
use App\Services\Imports\ShiftImportService;

/**
 * God-mode CSV import for shifts (technical spec 22.2).
 */
class ShiftImportScreen extends CsvImportScreen
{
    public function name(): ?string
    {
        return 'Import Shifts';
    }

    public function description(): ?string
    {
        return 'Create or update event shifts from a CSV. Each row names its event, department, and eligible team by slug and code. Shifts missing from the file are left alone; nothing here cancels a shift, and training or waiver requirements already set on a shift are kept.';
    }

    protected function routeName(): string
    {
        return 'platform.imports.shifts';
    }

    protected function formLayout(): string
    {
        return ShiftImportLayout::class;
    }

    protected function runImport(string $csv, User $actor, bool $preview): ImportResult
    {
        return app(ShiftImportService::class)->import($csv, $actor, $preview);
    }
}
