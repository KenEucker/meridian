<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Import;

use App\Models\User;
use App\Orchid\Layouts\Import\AssignmentImportLayout;
use App\Services\Imports\AssignmentImportService;
use App\Services\Imports\ImportResult;

/**
 * God-mode CSV import for shift assignments (technical spec 22.2).
 */
class AssignmentImportScreen extends CsvImportScreen
{
    public function name(): ?string
    {
        return 'Import Assignments';
    }

    public function description(): ?string
    {
        return 'Assign staff to existing shifts from a CSV. Rows are eligibility-checked exactly like a lead assignment, and are recorded as assigned by you. Nobody is removed from a shift by an import.';
    }

    protected function routeName(): string
    {
        return 'platform.imports.assignments';
    }

    protected function formLayout(): string
    {
        return AssignmentImportLayout::class;
    }

    protected function runImport(string $csv, User $actor, bool $preview): ImportResult
    {
        return app(AssignmentImportService::class)->import($csv, $actor, $preview);
    }
}
