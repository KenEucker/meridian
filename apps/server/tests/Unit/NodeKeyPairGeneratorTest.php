<?php

namespace Tests\Unit;

use App\Services\Node\NodeKeyPairGenerator;
use PHPUnit\Framework\TestCase;

class NodeKeyPairGeneratorTest extends TestCase
{
    public function test_generates_sodium_keypair_when_available(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this PHP runtime.');
        }

        $keys = (new NodeKeyPairGenerator)->generate();

        $this->assertSame(32, strlen(base64_decode($keys['public_key'], true)));
        $this->assertSame(64, strlen(base64_decode($keys['private_key'], true)));
    }

    public function test_openssl_fallback_generates_parseable_keypair(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL is not available in this PHP runtime.');
        }

        $keys = (new NodeKeyPairGenerator(preferSodium: false))->generate();

        $this->assertStringContainsString('BEGIN PUBLIC KEY', $keys['public_key']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $keys['private_key']);
        $this->assertNotFalse(openssl_pkey_get_public($keys['public_key']));
        $this->assertNotFalse(openssl_pkey_get_private($keys['private_key']));
    }
}
