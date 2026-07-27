<?php

namespace Tests\Unit;

use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\NodeSigningException;
use PHPUnit\Framework\TestCase;

/**
 * Detached signing over the node key formats Meridian actually generates
 * (technical spec 7.3, 10.4, 12.4).
 */
class NodeSignatureAlgorithmTest extends TestCase
{
    private NodeSignatureAlgorithm $algorithm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->algorithm = new NodeSignatureAlgorithm;
    }

    public function test_ed25519_signature_round_trips(): void
    {
        $keys = $this->ed25519Keys();

        $signature = $this->algorithm->sign('meridian operation payload', $keys['private_key']);

        $this->assertSame(
            NodeSignatureAlgorithm::ED25519,
            $this->algorithm->algorithmFor($keys['public_key']),
        );
        $this->assertTrue($this->algorithm->verify(
            'meridian operation payload',
            $signature,
            $keys['public_key'],
        ));
    }

    public function test_rsa_signature_round_trips(): void
    {
        $keys = $this->rsaKeys();

        $signature = $this->algorithm->sign('meridian operation payload', $keys['private_key']);

        $this->assertSame(
            NodeSignatureAlgorithm::RSA_SHA256,
            $this->algorithm->algorithmFor($keys['public_key']),
        );
        $this->assertTrue($this->algorithm->verify(
            'meridian operation payload',
            $signature,
            $keys['public_key'],
        ));
    }

    public function test_a_changed_message_does_not_verify(): void
    {
        $keys = $this->ed25519Keys();

        $signature = $this->algorithm->sign('meridian operation payload', $keys['private_key']);

        $this->assertFalse($this->algorithm->verify(
            'meridian operation payloae',
            $signature,
            $keys['public_key'],
        ));
    }

    public function test_a_signature_from_another_key_does_not_verify(): void
    {
        $signer = $this->ed25519Keys();
        $other = $this->ed25519Keys();

        $signature = $this->algorithm->sign('meridian operation payload', $signer['private_key']);

        $this->assertFalse($this->algorithm->verify(
            'meridian operation payload',
            $signature,
            $other['public_key'],
        ));
    }

    /**
     * A peer controls the signature bytes, so verification fails closed instead
     * of raising on anything malformed.
     */
    public function test_verification_fails_closed_for_unusable_input(): void
    {
        $keys = $this->ed25519Keys();

        $this->assertFalse($this->algorithm->verify('payload', '', $keys['public_key']));
        $this->assertFalse($this->algorithm->verify('payload', 'not base64 !!', $keys['public_key']));
        $this->assertFalse($this->algorithm->verify('payload', base64_encode('short'), $keys['public_key']));
        $this->assertFalse($this->algorithm->verify('payload', base64_encode(random_bytes(64)), $keys['public_key']));
        $this->assertFalse($this->algorithm->verify('payload', base64_encode(random_bytes(64)), ''));
        $this->assertFalse($this->algorithm->verify('payload', base64_encode(random_bytes(64)), 'not-a-key'));
        $this->assertFalse($this->algorithm->verify('payload', base64_encode(random_bytes(64)), base64_encode('too short')));
        $this->assertFalse($this->algorithm->verify(
            'payload',
            base64_encode(random_bytes(64)),
            "-----BEGIN PUBLIC KEY-----\ntruncated\n-----END PUBLIC KEY-----\n",
        ));
    }

    public function test_unsupported_key_material_is_refused_when_signing(): void
    {
        $this->expectException(NodeSigningException::class);

        $this->algorithm->sign('payload', base64_encode(random_bytes(16)));
    }

    public function test_unsupported_key_material_has_no_algorithm(): void
    {
        $this->expectException(NodeSigningException::class);

        $this->algorithm->algorithmFor('not-a-key');
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    private function ed25519Keys(): array
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium is not available in this PHP runtime.');
        }

        return (new NodeKeyPairGenerator)->generate();
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    private function rsaKeys(): array
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL is not available in this PHP runtime.');
        }

        return (new NodeKeyPairGenerator(preferSodium: false))->generate();
    }
}
