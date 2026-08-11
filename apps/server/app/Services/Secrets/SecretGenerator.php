<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Models\Node;
use App\Services\Node\NodeSetupService;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Generates the secrets Meridian owns when they are missing or still a sample
 * value (technical spec 7.4: "setup should generate secrets if they are missing
 * or still set to defaults, including Laravel APP_KEY, node keys, service
 * secrets"; technical spec 26.2).
 *
 * Meridian owns two of them. `APP_KEY` is Laravel's, and generating it is
 * `key:generate` writing the node's own environment file. The node keypair is
 * this install's identity, and generating it is node setup's own path, reused
 * here for a node that arrived without one.
 *
 * The service secrets in the same sentence of the specification are not
 * Meridian's to mint, and this says so rather than pretending. A database
 * password belongs to the database, an SMTP password to the mail account, an
 * OAuth client secret to the provider that issued it; generating any of them
 * would replace a working credential with one nothing on the other side
 * accepts. They are reported as needing a human, which is what makes the
 * boot refusal actionable instead of circular.
 */
class SecretGenerator
{
    public function __construct(
        private readonly SecretInventory $inventory,
        private readonly SecretSafeguard $safeguard,
        private readonly NodeSetupService $nodes,
    ) {}

    /**
     * Generate what can be generated and report on everything else.
     *
     * @return list<SecretGenerationResult>
     */
    public function generate(): array
    {
        return [
            $this->generateApplicationKey(),
            $this->generateNodeKeys(),
            ...$this->reportUngeneratableFailures(),
        ];
    }

    /**
     * Laravel's application key, written to this node's environment file.
     *
     * A deployed container has no environment file — the deployment's values
     * arrive as process environment, and the image deliberately ships without
     * one so no builder's `.env` is ever baked into a layer. There is nothing to
     * write to there, and writing to a file the next container start would not
     * read would be worse than refusing, so this reports what the operator has
     * to do instead.
     */
    private function generateApplicationKey(): SecretGenerationResult
    {
        $requirement = $this->inventory->requirement(SecretInventory::APP_KEY);
        $current = trim((string) config('app.key', ''));

        if ($current !== '' && ! ($requirement?->isDefaultValue($current) ?? false)) {
            return SecretGenerationResult::unchanged(
                SecretInventory::APP_KEY,
                'Already set; left alone. Regenerating it would make every existing session, signed URL, and encrypted value unreadable.',
            );
        }

        $environmentFile = app()->environmentFilePath();

        if (! is_file($environmentFile) || ! is_writable($environmentFile)) {
            return SecretGenerationResult::manual(
                SecretInventory::APP_KEY,
                'No writable environment file on this node, so a generated key could not be persisted. Generate one with `php artisan key:generate --show` and set APP_KEY in this node\'s deployment environment.',
            );
        }

        try {
            Artisan::call('key:generate', ['--force' => true]);
        } catch (Throwable $exception) {
            return SecretGenerationResult::manual(
                SecretInventory::APP_KEY,
                'Generation failed ('.$exception::class.'). Generate one with `php artisan key:generate --show` and set APP_KEY in this node\'s deployment environment.',
            );
        }

        return SecretGenerationResult::generated(
            SecretInventory::APP_KEY,
            'Generated and written to '.basename($environmentFile).'.',
        );
    }

    /**
     * The node keypair, for a configured node that has none.
     *
     * An install with no node yet is not a fault here: first-run setup creates
     * the node and its keys together, and there is no identity to key until
     * somebody has said what this node is.
     */
    private function generateNodeKeys(): SecretGenerationResult
    {
        try {
            $node = $this->nodes->activeNode();
        } catch (Throwable $exception) {
            return SecretGenerationResult::manual(
                SecretInventory::NODE_PRIVATE_KEY,
                'This node\'s record could not be read ('.$exception::class.'), so key state is unknown. Run migrations, then run this command again.',
            );
        }

        if (! $node instanceof Node) {
            return SecretGenerationResult::manual(
                SecretInventory::NODE_PRIVATE_KEY,
                'This install has no configured node yet. Complete first-run setup at /setup or on the God Mode Node Configuration screen; it generates the keypair with the node.',
            );
        }

        return match ($this->nodes->ensureNodeKeys($node)) {
            NodeSetupService::KEYS_GENERATED => SecretGenerationResult::generated(
                SecretInventory::NODE_PRIVATE_KEY,
                'Generated a keypair for node '.$node->node_name.' and stored it as node configuration.',
            ),
            NodeSetupService::KEYS_INCOMPLETE => SecretGenerationResult::manual(
                SecretInventory::NODE_PRIVATE_KEY,
                'Node '.$node->node_name.' holds one half of a keypair and not the other. Nothing was generated: replacing a key orphans every operation this node has signed, so restoring the missing half or re-pairing with central is an operator\'s decision.',
            ),
            default => SecretGenerationResult::unchanged(
                SecretInventory::NODE_PRIVATE_KEY,
                'Node '.$node->node_name.' already holds a keypair; left alone.',
            ),
        };
    }

    /**
     * Everything still failing that Meridian cannot generate.
     *
     * @return list<SecretGenerationResult>
     */
    private function reportUngeneratableFailures(): array
    {
        $results = [];

        foreach ($this->safeguard->evaluate()->failures() as $failure) {
            if ($failure->requirement->generatable) {
                continue;
            }

            $results[] = SecretGenerationResult::manual(
                $failure->requirement->name,
                (string) $failure->reason,
            );
        }

        return $results;
    }
}
