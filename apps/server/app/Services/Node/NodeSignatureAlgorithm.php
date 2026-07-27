<?php

namespace App\Services\Node;

use SodiumException;
use Throwable;

/**
 * Detached signing and verification over the two key formats Meridian nodes
 * and devices actually hold (technical spec 7.3, 10.4, 12.4).
 *
 * {@see NodeKeyPairGenerator} produces base64 Ed25519 keys where libsodium is
 * available and falls back to PEM RSA otherwise, so both must sign and verify.
 * The algorithm is derived from the key material rather than stored alongside
 * the signature: `node_operations.signature` is a single signature value in
 * data/API 13.3, and a signature can only ever be checked against the key that
 * produced it anyway.
 *
 * Signatures are base64 so they survive JSON transport between nodes.
 *
 * Verification never raises. An untrusted peer controls the signature and can
 * send anything, so malformed input, unusable key material, and a genuinely
 * bad signature all return false and let the caller fail closed. Signing does
 * raise, because a signing failure means our own node is misconfigured.
 */
class NodeSignatureAlgorithm
{
    /** Base64 libsodium Ed25519 key material. */
    public const ED25519 = 'ed25519';

    /** PEM RSA key material, signed over SHA-256. */
    public const RSA_SHA256 = 'rsa-sha256';

    /**
     * Name the algorithm a key belongs to, for audit signature metadata.
     *
     * @throws NodeSigningException
     */
    public function algorithmFor(string $key): string
    {
        if ($this->isPem($key)) {
            return self::RSA_SHA256;
        }

        if ($this->rawKey($key) !== null) {
            return self::ED25519;
        }

        throw NodeSigningException::unsupportedKeyMaterial();
    }

    /**
     * @return string base64 detached signature over $message
     *
     * @throws NodeSigningException
     */
    public function sign(string $message, string $privateKey): string
    {
        if ($this->isPem($privateKey)) {
            return $this->signWithOpenSsl($message, $privateKey);
        }

        $raw = $this->rawKey($privateKey);

        if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw NodeSigningException::unsupportedKeyMaterial();
        }

        try {
            return base64_encode(sodium_crypto_sign_detached($message, $raw));
        } catch (SodiumException) {
            throw NodeSigningException::signingFailed();
        }
    }

    /**
     * @param  string  $signature  base64 detached signature
     */
    public function verify(string $message, string $signature, string $publicKey): bool
    {
        if (trim($signature) === '' || trim($publicKey) === '') {
            return false;
        }

        $rawSignature = base64_decode($signature, true);

        if ($rawSignature === false) {
            return false;
        }

        try {
            if ($this->isPem($publicKey)) {
                // A peer supplies this key material, so a malformed key is
                // expected input rather than a fault worth emitting a PHP
                // warning for. Parse it first and let a failed parse be the
                // answer.
                $key = @openssl_pkey_get_public($publicKey);

                return $key !== false
                    && openssl_verify($message, $rawSignature, $key, OPENSSL_ALGO_SHA256) === 1;
            }

            $raw = $this->rawKey($publicKey);

            if ($raw === null
                || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($rawSignature, $message, $raw);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws NodeSigningException
     */
    private function signWithOpenSsl(string $message, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw NodeSigningException::unsupportedKeyMaterial();
        }

        if (! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw NodeSigningException::signingFailed();
        }

        return base64_encode($signature);
    }

    private function isPem(string $key): bool
    {
        return str_contains($key, '-----BEGIN');
    }

    /**
     * Decode base64 key material, rejecting anything that is not a plausible
     * Ed25519 public or secret key.
     */
    private function rawKey(string $key): ?string
    {
        $raw = base64_decode(trim($key), true);

        if ($raw === false) {
            return null;
        }

        $lengths = [SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES];

        return in_array(strlen($raw), $lengths, true) ? $raw : null;
    }
}
