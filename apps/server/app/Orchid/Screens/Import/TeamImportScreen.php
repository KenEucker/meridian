<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Import;

use App\Models\User;
use App\Orchid\Layouts\Import\TeamImportLayout;
use App\Services\Imports\ImportResult;
use App\Services\Imports\TeamImportService;

/**
 * God-mode spreadsheet and CSV import for teams (technical spec 22.2).
 */
class TeamImportScreen extends ImportScreen
{
    public function name(): ?string
    {
        return 'Import Teams';
    }

    public function description(): ?string
    {
        return 'Create or update department teams from a spreadsheet or CSV. Each row names its department by organization slug and department code. Teams missing from the file are left alone; nothing here archives or deletes a team.';
    }

    protected function routeName(): string
    {
        return 'platform.imports.teams';
    }

    protected function formLayout(): string
    {
        return TeamImportLayout::class;
    }

    protected function runImport(string $file, User $actor, bool $preview): ImportResult
    {
        return app(TeamImportService::class)->import($file, $actor, $preview);
    }
}
