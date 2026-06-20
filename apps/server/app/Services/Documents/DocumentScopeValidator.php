<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Department;
use App\Models\DocumentFragment;
use App\Models\Team;

/**
 * Validates the canonical organization, department, and team document scopes
 * used by policy documents, procedure documents, and document fragments.
 */
class DocumentScopeValidator
{
    public function isValid(string $organizationId, string $scopeType, string $scopeId): bool
    {
        return match ($scopeType) {
            DocumentFragment::SCOPE_ORGANIZATION => $scopeId === $organizationId,
            DocumentFragment::SCOPE_DEPARTMENT => Department::query()
                ->whereKey($scopeId)
                ->where('organization_id', $organizationId)
                ->exists(),
            DocumentFragment::SCOPE_TEAM => Team::query()
                ->whereKey($scopeId)
                ->whereHas('department', fn ($query) => $query->where('organization_id', $organizationId))
                ->exists(),
            default => false,
        };
    }

    public function departmentId(string $scopeType, string $scopeId): ?string
    {
        return match ($scopeType) {
            DocumentFragment::SCOPE_DEPARTMENT => $scopeId,
            DocumentFragment::SCOPE_TEAM => Team::query()
                ->whereKey($scopeId)
                ->value('department_id'),
            default => null,
        };
    }
}
