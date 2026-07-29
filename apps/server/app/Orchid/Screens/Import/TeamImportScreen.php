<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Import;

use App\Models\User;
use App\Orchid\Layouts\Import\TeamImportLayout;
use App\Services\Imports\ImportResult;
use App\Services\Imports\TeamImportService;

/**
 * God-mode CSV import for teams (technical spec 22.2).
 */
class TeamImportScreen extends CsvImportScreen
{
    public function name(): ?string
    {
        return 'Import Teams';
    }

    public function description(): ?string
    {
        return 'Create or update department teams from a CSV. Each row names its department by organization slug and department code. Teams missing from the file are left alone; nothing here archives or deletes a team.';
    }

    protected function routeName(): string
    {
        return 'platform.imports.teams';
    }

    protected function formLayout(): string
    {
        return TeamImportLayout::class;
    }

    protected function runImport(string $csv, User $actor, bool $preview): ImportResult
    {
        return app(TeamImportService::class)->import($csv, $actor, $preview);
    }
}
