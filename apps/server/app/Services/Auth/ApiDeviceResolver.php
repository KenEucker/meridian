<?php

namespace App\Services\Auth;

use App\Models\Device;
use App\Services\Node\NodeSignatureAlgorithm;
use App\Services\Node\NodeSigningException;
use Illuminate\Support\Str;

/**
 * Resolves the `devices` record a bearer token will be bound to (AUTH-021;
 * technical spec 11.4; data/API 12.1, 12.5).
 *
 * A client names itself with a stable install identifier it generates once and
 * keeps. The first time the node sees that identifier it registers the device
 * from the descriptive fields the client sends with it; afterwards the same
 * identifier resolves to the same row. There is no separate registration
 * endpoint in Alpha 1, and `devices.first_seen_at` is the column that says this
 * is where a device record begins.
 *
 * Resolution is deliberately two steps. {@see identify()} decides whether a
 * device is usable and writes nothing; {@see register()} writes. The login code
 * is redeemed between them, so a device refusal does not spend a single-use
 * code the person would have to request again, and a failed sign-in does not
 * leave a `devices` row behind for hardware that never authenticated.
 *
 * Every refusal path here is a refusal to issue a token, which is what AUTH-021
 * asks for: "a token that cannot be associated with a device shall not be
 * issued". A request with no device identity, an identity the node cannot
 * register, or a revoked device gets no token rather than an unbound one.
 *
 * Registration requires usable key material, verified through the same
 * algorithm that checks a device signature on a node operation (technical spec
 * 12.4). A device registered with a key nothing can verify would sign in today
 * and fail closed later, at the point where its signature actually matters.
 */
class ApiDeviceResolver
{
    /**
     * Bounded so an unbounded body cannot be written into `device_public_key`.
     * A PEM RSA public key is the longer of the two formats Meridian accepts
     * and is well under this.
     */
    public const MAX_PUBLIC_KEY_LENGTH = 4096;

    public function __construct(private readonly NodeSignatureAlgorithm $signatures) {}

    /**
     * Check that a request names a device a token could be bound to, without
     * writing anything.
     *
     * @param  array<string, mixed>|null  $payload  the request's `device` object
     *
     * @throws ApiLoginException when no usable, unrevoked device is named
     */
    public function identify(?array $payload): DeviceIdentity
    {
        $id = $this->string($payload, 'id');

        if ($id === null || ! Str::isUuid($id)) {
            throw ApiLoginException::deviceUnresolvable(
                'This client did not identify the device it is signing in from. A device identifier is required.',
            );
        }

        $device = Device::query()->find($id);

        if ($device instanceof Device) {
            if ($device->isRevoked()) {
                throw ApiLoginException::deviceRevoked();
            }

            return new DeviceIdentity(id: $id, existing: $device);
        }

        $label = $this->string($payload, 'label');
        $platform = $this->string($payload, 'platform');
        $publicKey = $this->string($payload, 'public_key');

        if ($label === null || $platform === null || $publicKey === null) {
            throw ApiLoginException::deviceUnresolvable(
                'This device is not registered with this node. Registering it needs a label, a platform, and a device public key.',
            );
        }

        if (strlen($publicKey) > self::MAX_PUBLIC_KEY_LENGTH || ! $this->isUsableKeyMaterial($publicKey)) {
            throw ApiLoginException::deviceUnresolvable(
                'This device sent key material this node cannot use. Meridian accepts a base64 Ed25519 public key or a PEM RSA public key.',
            );
        }

        return new DeviceIdentity(
            id: $id,
            label: $label,
            platform: $platform,
            publicKey: $publicKey,
        );
    }

    /**
     * Write the identified device: register it on first sight, or mark an
     * already-registered one as seen.
     *
     * @throws ApiLoginException if the device was revoked since it was
     *                           identified
     */
    public function register(DeviceIdentity $identity): Device
    {
        $device = $identity->existing;

        if ($device instanceof Device) {
            // Re-read rather than trusting the instance from identification:
            // this runs after the login code was redeemed, and a revocation
            // that landed in between has to win.
            $device->refresh();

            if ($device->isRevoked()) {
                throw ApiLoginException::deviceRevoked();
            }

            $device->forceFill(['last_seen_at' => now()])->save();

            return $device;
        }

        return Device::query()->create([
            'id' => $identity->id,
            'device_label' => Str::limit((string) $identity->label, 255, ''),
            'platform' => Str::limit((string) $identity->platform, 64, ''),
            'device_public_key' => (string) $identity->publicKey,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function isUsableKeyMaterial(string $publicKey): bool
    {
        try {
            $this->signatures->algorithmFor($publicKey);
        } catch (NodeSigningException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function string(?array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
