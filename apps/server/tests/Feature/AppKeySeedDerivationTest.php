<?php

namespace Tests\Feature;

use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

/**
 * The APP_KEY seed derivation (M19.27; technical spec 7.4, 26.2;
 * deploy/runtipi/README.md).
 *
 * The derivation itself runs in the container entrypoint, in shell, where no
 * PHPUnit test can execute it. So this test replicates the exact expression
 * the entrypoint evaluates and proves the two things the shell cannot: that
 * the derived key is one Laravel's encrypter genuinely accepts — for any seed
 * length, which is what makes guessing at Runtipi's `random` field semantics
 * unnecessary — and that it is deterministic, which is what makes the key
 * survive a container recreate, an app update, and a restore from the
 * platform's own backup. A pin against the entrypoint source keeps the
 * replicated expression from drifting away from the one that ships.
 */
class AppKeySeedDerivationTest extends TestCase
{
    /**
     * The expression the entrypoint evaluates with `php -r`, verbatim.
     */
    private function deriveKey(string $seed): string
    {
        return 'base64:'.base64_encode(hash('sha256', $seed, true));
    }

    public function test_the_entrypoint_derives_with_this_exact_expression(): void
    {
        $entrypoint = file_get_contents(base_path('../../deploy/docker/entrypoint.sh'));

        $this->assertStringContainsString(
            'echo base64_encode(hash("sha256", getenv("MERIDIAN_APP_KEY_SEED"), true));',
            $entrypoint,
            'The entrypoint derivation no longer matches the expression this test replicates. Change both together.',
        );

        $this->assertStringContainsString(
            '[ -z "${APP_KEY:-}" ] && [ -n "${MERIDIAN_APP_KEY_SEED:-}" ]',
            $entrypoint,
            'The derivation must run only when APP_KEY is unset and a seed is present, so it stays inert for every existing deployment.',
        );
    }

    public function test_a_derived_key_is_a_valid_laravel_key_for_any_seed_length(): void
    {
        $seeds = [
            str_repeat('a1', 32),        // 64 hex characters, the QA form field shape
            str_repeat('f0e1', 32),      // 128 characters, the other reading of min: 64
            'short',                     // degenerate but still derivable
        ];

        foreach ($seeds as $seed) {
            $key = $this->deriveKey($seed);

            $this->assertStringStartsWith('base64:', $key);

            $bytes = base64_decode(substr($key, 7), true);

            $this->assertSame(32, strlen($bytes), 'SHA-256 must yield exactly the 32 key bytes AES-256 requires.');

            $encrypter = new Encrypter($bytes, config('app.cipher'));
            $payload = 'a value the worker must decrypt after the web container encrypted it';

            $this->assertSame($payload, $encrypter->decrypt($encrypter->encrypt($payload)));
        }
    }

    public function test_the_derivation_is_deterministic_and_seed_distinct(): void
    {
        $seed = str_repeat('c3', 32);

        $this->assertSame($this->deriveKey($seed), $this->deriveKey($seed));
        $this->assertNotSame($this->deriveKey($seed), $this->deriveKey(str_repeat('d4', 32)));
    }
}
