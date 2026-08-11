<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Domain\Modules\ModuleKey;
use RuntimeException;

/**
 * A request reached capability its organization does not run (MOD-012,
 * MOD-013; technical spec 15A.4; data/API 5.9; M19.12).
 *
 * Thrown rather than returned, for two reasons. It is raised from middleware
 * that runs before the handler and has no response to shape, and MOD-017's
 * outbox replay will need to raise the same condition from a place that is not
 * an HTTP response at all — a queued write against a module that went inactive
 * while the device was away becomes a sync conflict, and it has to be the same
 * refusal being recognized rather than a second rule that happens to agree.
 *
 * It renders as `404` with `{"error": {"code": "module_inactive", "module":
 * "<key>"}}` (bootstrap/app.php; data/API 5.9). Not-found rather than forbidden
 * because the capability is *absent*, not withheld: there is no permission that
 * would reach it and no one — God Mode included — for whom the answer differs.
 *
 * Naming the module in the refusal is deliberate. Module state is an
 * organization's own configuration and not a secret from its members, and the
 * client needs the difference between "your organization does not use
 * Scheduling" and "you cannot manage shifts" in order to say the right one
 * (MOD-013; data/API 6.8).
 */
class ModuleInactiveException extends RuntimeException
{
    public function __construct(
        public readonly ModuleKey $module,
        public readonly ?string $organizationId = null,
    ) {
        parent::__construct(sprintf(
            'This organization does not use %s.',
            $module->label(),
        ));
    }

    /**
     * The refusal body, shared by the HTTP renderer and by anything else that
     * has to report this condition in the same words.
     *
     * @return array{message: string, error: array{code: string, module: string}}
     */
    public function payload(): array
    {
        return [
            'message' => $this->getMessage(),
            'error' => [
                'code' => 'module_inactive',
                'module' => $this->module->value,
            ],
        ];
    }
}
