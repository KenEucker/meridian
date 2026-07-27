<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use App\Services\Node\NodeKeyPairGenerator;
use App\Services\Node\NodeOperationSigner;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\NodeSigningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Node operation signing and verification (technical spec 10.4, 12.4).
 */
class NodeOperationSigningTest extends TestCase
{
    use RefreshDatabase;

    private NodeOperationSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = app(NodeOperationSigner::class);
    }

    public function test_signature_covers_the_normalized_fields_only(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node, [
            'payload_json' => ['status' => 'closed'],
        ]);

        $payload = $this->signer->canonicalPayload($operation);

        [$format, $json] = explode("\n", $payload, 2);

        $this->assertSame(NodeOperationSigner::CANONICAL_FORMAT, $format);
        $this->assertSame(
            $operation->normalizedAttributes(),
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            NodeOperation::NORMALIZED_ATTRIBUTES,
            array_keys(json_decode($json, true, flags: JSON_THROW_ON_ERROR)),
        );

        foreach (['payload_json', 'signature', 'hash', ...NodeOperation::LIFECYCLE_ATTRIBUTES] as $excluded) {
            $this->assertStringNotContainsString('"'.$excluded.'"', $json);
        }
    }

    public function test_canonical_payload_does_not_depend_on_attribute_assignment_order(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $attributes = $this->attributesFor($node);

        $forward = new NodeOperation;
        $forward->forceFill($attributes);

        $reversed = new NodeOperation;
        $reversed->forceFill(array_reverse($attributes, preserve_keys: true));

        $this->assertSame(
            $this->signer->canonicalPayload($forward),
            $this->signer->canonicalPayload($reversed),
        );
    }

    public function test_signing_records_the_signature_and_hash_and_verifies(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $signature = $this->signer->sign($operation, $node);

        $this->assertSame($signature->signature, $operation->signature);
        $this->assertSame($signature->hash, $operation->hash);
        $this->assertSame($this->signer->hashFor($operation), $operation->hash);
        $this->assertSame((string) $node->getKey(), $signature->signingNodeId);
        $this->assertFalse($signature->isCountersignature());
        $this->assertTrue($this->signer->verify($operation, $node));

        $operation->save();

        $this->assertTrue($this->signer->verify($operation->fresh()));
    }

    public function test_signing_uses_the_configured_node_private_key(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);

        $this->assertTrue(app(NodeSignatureAlgorithm::class)->verify(
            $this->signer->canonicalPayload($operation),
            (string) $operation->signature,
            $node->public_key,
        ));
    }

    public function test_signing_falls_back_to_this_installs_active_node(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node, ['origin_node_id' => null]);

        $signature = $this->signer->sign($operation);

        $this->assertSame((string) $node->getKey(), $operation->origin_node_id);
        $this->assertSame((string) $node->getKey(), $signature->signingNodeId);
        $this->assertTrue($this->signer->verify($operation));
    }

    public function test_rsa_node_keys_sign_and_verify(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL is not available in this PHP runtime.');
        }

        $keys = (new NodeKeyPairGenerator(preferSodium: false))->generate();
        $node = Node::factory()->onsite()->signing($keys)->create();
        $operation = $this->operationFor($node);

        $signature = $this->signer->sign($operation, $node);

        $this->assertSame(NodeSignatureAlgorithm::RSA_SHA256, $signature->algorithm);
        $this->assertTrue($this->signer->verify($operation, $node));
    }

    public function test_a_rewritten_normalized_field_fails_verification(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);

        $operation->entity_id = (string) Str::uuid();

        $this->assertFalse($this->signer->verify($operation, $node));
    }

    public function test_a_rewritten_hash_fails_verification(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);

        $operation->hash = hash('sha256', 'forged');

        $this->assertFalse($this->signer->verify($operation, $node));
    }

    public function test_a_signature_from_another_node_fails_verification(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $other = Node::factory()->central()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);

        $this->assertFalse($this->signer->verify($operation, $other));
    }

    public function test_verification_fails_closed_when_the_signature_or_signer_is_unusable(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);
        $signature = (string) $operation->signature;

        $operation->signature = '';
        $this->assertFalse($this->signer->verify($operation, $node));

        $operation->signature = 'not base64 !!';
        $this->assertFalse($this->signer->verify($operation, $node));

        $operation->signature = $signature;
        $node->public_key = '';
        $this->assertFalse($this->signer->verify($operation, $node));

        $node->public_key = 'not-a-key';
        $this->assertFalse($this->signer->verify($operation, $node));
    }

    public function test_verification_fails_closed_when_the_origin_node_is_unknown(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);

        $operation->origin_node_id = (string) Str::uuid();

        $this->assertFalse($this->signer->verify($operation));
    }

    public function test_device_originated_operations_are_countersigned_by_the_accepting_node(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $deviceKeys = (new NodeKeyPairGenerator)->generate();
        $device = Device::factory()->create(['device_public_key' => $deviceKeys['public_key']]);

        $operation = $this->operationFor($node, ['actor_device_id' => $device->id]);
        $deviceSignature = app(NodeSignatureAlgorithm::class)->sign(
            $this->signer->canonicalPayload($operation),
            $deviceKeys['private_key'],
        );

        $this->assertTrue($this->signer->verifyDeviceSignature($operation, $deviceSignature, $device));

        $signature = $this->signer->countersign($operation, $deviceSignature, $device, $node);

        $this->assertTrue($signature->isCountersignature());
        $this->assertSame($deviceSignature, $signature->deviceSignature);
        $this->assertSame((string) $device->getKey(), $signature->deviceId);
        $this->assertSame($signature->signature, $operation->signature);
        $this->assertNotSame($deviceSignature, $operation->signature);
        $this->assertTrue($this->signer->verify($operation, $node));
    }

    public function test_an_unverifiable_device_signature_is_not_countersigned(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $deviceKeys = (new NodeKeyPairGenerator)->generate();
        $device = Device::factory()->create(['device_public_key' => $deviceKeys['public_key']]);

        $operation = $this->operationFor($node, ['actor_device_id' => $device->id]);
        $forged = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));

        $this->assertFalse($this->signer->verifyDeviceSignature($operation, $forged, $device));

        try {
            $this->signer->countersign($operation, $forged, $device, $node);
            $this->fail('An unverifiable device signature should not be countersigned.');
        } catch (NodeSigningException) {
            // Expected: the accepting node refuses to countersign.
        }

        $this->assertNull($operation->signature);
        $this->assertNull($operation->hash);
    }

    public function test_a_device_with_no_registered_public_key_fails_closed(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $device = Device::factory()->create(['device_public_key' => '']);
        $operation = $this->operationFor($node, ['actor_device_id' => $device->id]);

        $this->assertFalse($this->signer->verifyDeviceSignature($operation, 'anything', $device));
    }

    public function test_countersigning_requires_the_operations_acting_device(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $deviceKeys = (new NodeKeyPairGenerator)->generate();
        $device = Device::factory()->create(['device_public_key' => $deviceKeys['public_key']]);
        $otherDevice = Device::factory()->create(['device_public_key' => $deviceKeys['public_key']]);

        $operation = $this->operationFor($node, ['actor_device_id' => $device->id]);
        $deviceSignature = app(NodeSignatureAlgorithm::class)->sign(
            $this->signer->canonicalPayload($operation),
            $deviceKeys['private_key'],
        );

        $this->expectException(NodeSigningException::class);

        $this->signer->countersign($operation, $deviceSignature, $otherDevice, $node);
    }

    public function test_a_recorded_operation_cannot_be_signed_again(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $this->signer->sign($operation, $node);
        $operation->save();

        $this->expectException(NodeSigningException::class);

        $this->signer->sign($operation, $node);
    }

    public function test_signing_requires_a_configured_node_private_key(): void
    {
        $node = Node::factory()->onsite()->create();
        $operation = $this->operationFor($node);

        $this->expectException(NodeSigningException::class);

        $this->signer->sign($operation, $node);
    }

    public function test_a_peer_node_cannot_be_used_to_sign(): void
    {
        $peer = Node::factory()->remote()->central()->signing()->create();
        $operation = $this->operationFor($peer);

        $this->expectException(NodeSigningException::class);

        $this->signer->sign($operation, $peer);
    }

    public function test_signing_refuses_an_operation_that_originated_on_another_node(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $peer = Node::factory()->remote()->central()->create();
        $operation = $this->operationFor($node, ['origin_node_id' => $peer->id]);

        $this->expectException(NodeSigningException::class);

        $this->signer->sign($operation, $node);
    }

    public function test_signing_refuses_an_operation_missing_normalized_fields(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node, ['uuid' => '']);

        $this->expectException(NodeSigningException::class);

        $this->signer->sign($operation, $node);
    }

    /**
     * `created_at` is covered by the signature, so it is pinned at signing time
     * rather than filled in by the insert (technical spec 10.4).
     */
    public function test_signing_pins_the_origin_creation_time(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node, ['created_at' => null]);

        $this->signer->sign($operation, $node);

        $this->assertNotNull($operation->created_at);

        $signedAt = $operation->created_at->copy();

        $this->travel(5)->minutes();
        $operation->save();

        $this->assertTrue($operation->fresh()->created_at->equalTo($signedAt));
        $this->assertTrue($this->signer->verify($operation->fresh(), $node));
    }

    public function test_audit_retains_the_node_signature(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $signature = $this->signer->sign($operation, $node);
        $operation->save();

        $audit = $this->signer->recordSignatures($operation, $signature);

        $this->assertSame('node_operation.signed', $audit->action);
        $this->assertSame((string) $operation->getKey(), $audit->entity_id);
        $this->assertSame((string) $node->getKey(), $audit->actor_node_id);
        $this->assertSame(AuditEvent::SOURCE_SYNC, $audit->source_context);
        $this->assertSame($operation->signature, $audit->signature_metadata_json['node_signature']);
        $this->assertSame($operation->hash, $audit->signature_metadata_json['hash']);
        $this->assertFalse($audit->signature_metadata_json['countersigned']);
        $this->assertArrayNotHasKey('device_signature', $audit->signature_metadata_json);
    }

    /**
     * The row holds the node countersignature alone, so audit data is where
     * both signatures are retained (technical spec 12.4; data/API 13.3).
     */
    public function test_audit_retains_both_signatures_for_a_countersigned_operation(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $deviceKeys = (new NodeKeyPairGenerator)->generate();
        $device = Device::factory()->create(['device_public_key' => $deviceKeys['public_key']]);

        $operation = $this->operationFor($node, ['actor_device_id' => $device->id]);
        $deviceSignature = app(NodeSignatureAlgorithm::class)->sign(
            $this->signer->canonicalPayload($operation),
            $deviceKeys['private_key'],
        );

        $signature = $this->signer->countersign($operation, $deviceSignature, $device, $node);
        $operation->save();

        $audit = $this->signer->recordSignatures($operation, $signature);

        $this->assertSame('node_operation.countersigned', $audit->action);
        $this->assertSame((string) $device->getKey(), $audit->actor_device_id);
        $this->assertTrue($audit->signature_metadata_json['countersigned']);
        $this->assertSame($deviceSignature, $audit->signature_metadata_json['device_signature']);
        $this->assertSame($operation->signature, $audit->signature_metadata_json['node_signature']);
        $this->assertSame(
            NodeSignatureAlgorithm::ED25519,
            $audit->signature_metadata_json['device_signature_algorithm'],
        );
    }

    public function test_signatures_can_only_be_audited_after_the_operation_is_recorded(): void
    {
        $node = Node::factory()->onsite()->signing()->create();
        $operation = $this->operationFor($node);

        $signature = $this->signer->sign($operation, $node);

        $this->expectException(NodeSigningException::class);

        $this->signer->recordSignatures($operation, $signature);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function operationFor(Node $node, array $overrides = []): NodeOperation
    {
        $operation = new NodeOperation;

        $operation->forceFill(array_merge($this->attributesFor($node), $overrides));

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(Node $node): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'origin_node_id' => $node->id,
            'target_node_id' => null,
            'actor_user_id' => User::factory()->create()->id,
            'actor_device_id' => null,
            'operation_type' => 'upsert',
            'entity_type' => 'field_report',
            'entity_id' => (string) Str::uuid(),
            'event_id' => null,
            'created_at' => now()->startOfSecond(),
            'status' => NodeOperation::STATUS_PENDING,
            'retry_count' => 0,
        ];
    }
}
