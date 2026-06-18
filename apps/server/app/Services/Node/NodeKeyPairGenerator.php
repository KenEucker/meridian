<?php

namespace App\Services\Node;

use RuntimeException;

class NodeKeyPairGenerator
{
    public function __construct(private readonly bool $preferSodium = true) {}

    /**
     * @return array{public_key: string, private_key: string}
     */
    public function generate(): array
    {
        if ($this->preferSodium && function_exists('sodium_crypto_sign_keypair')) {
            return $this->generateWithSodium();
        }

        return $this->generateWithOpenSsl();
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    private function generateWithSodium(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
        ];
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    private function generateWithOpenSsl(): array
    {
        if (! function_exists('openssl_pkey_new')) {
            throw new RuntimeException('The sodium or openssl extension is required to generate Meridian node keys.');
        }

        $options = [
            'private_key_bits' => 3072,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $configPath = $this->openSslConfigPath();

        if ($configPath !== null) {
            $options['config'] = $configPath;
        }

        $key = openssl_pkey_new($options);

        if ($key === false || ! openssl_pkey_export($key, $privateKey, null, $options)) {
            throw new RuntimeException('Unable to generate a Meridian node keypair.');
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details) || ! isset($details['key'])) {
            throw new RuntimeException('Unable to read the generated Meridian node public key.');
        }

        return [
            'public_key' => $details['key'],
            'private_key' => $privateKey,
        ];
    }

    private function openSslConfigPath(): ?string
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
