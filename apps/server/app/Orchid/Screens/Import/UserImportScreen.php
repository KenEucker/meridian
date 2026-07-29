<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Import;

use App\Models\User;
use App\Orchid\Layouts\Import\UserImportLayout;
use App\Services\Imports\ImportResult;
use App\Services\Imports\UserImportService;

/**
 * God-mode CSV import for users (technical spec 22.2).
 */
class UserImportScreen extends CsvImportScreen
{
    public function name(): ?string
    {
        return 'Import Users';
    }

    public function description(): ?string
    {
        return 'Create or update user accounts from a CSV of email addresses and names. The file cannot grant console access, roles, or passwords, and it never removes an account.';
    }

    protected function routeName(): string
    {
        return 'platform.imports.users';
    }

    protected function formLayout(): string
    {
        return UserImportLayout::class;
    }

    protected function runImport(string $csv, User $actor, bool $preview): ImportResult
    {
        return app(UserImportService::class)->import($csv, $actor, $preview);
    }
}
