<?php

declare(strict_types=1);

namespace App\Services\Secrets;

/**
 * The secrets a Meridian node is refused production or event service without
 * (technical spec 7.4, 26.2).
 *
 * The list is defined here rather than derived from `.env.example`, for two
 * reasons. The refusal runs at boot, before the configuration cache, the
 * database, and the catalogue cache can be relied on, and it must not depend on
 * parsing a file that a stripped-down image might not carry. And the three
 * things this list adds to what the catalogue knows — whether a blank value is a
 * refusal, whether Meridian can mint a replacement, and whether the node uses
 * the service at all — are judgements, not metadata.
 *
 * The two are kept honest against each other by test rather than at runtime:
 * `SecretSafeguardTest` asserts that every `@secret` entry in `.env.example` is
 * classified here, and that each one's sample value is blank or listed as a
 * default. That test is also what enforces "sample configs must contain fake
 * values only" (technical spec 7.4) for the server's own sample — a real secret
 * committed to `.env.example` would have to be added to a list named
 * `defaultValues` to pass, which nobody does by accident.
 */
final class SecretInventory
{
    public const APP_KEY = 'APP_KEY';

    public const NODE_PRIVATE_KEY = 'MERIDIAN_NODE_PRIVATE_KEY';

    public const DB_PASSWORD = 'DB_PASSWORD';

    public const REDIS_PASSWORD = 'REDIS_PASSWORD';

    public const MAIL_PASSWORD = 'MAIL_PASSWORD';

    public const GOOGLE_OAUTH_CLIENT_SECRET = 'GOOGLE_OAUTH_CLIENT_SECRET';

    public const DISCORD_OAUTH_CLIENT_SECRET = 'DISCORD_OAUTH_CLIENT_SECRET';

    public const CHANGELOG_TOKEN = 'MERIDIAN_CHANGELOG_TOKEN';

    public const AWS_SECRET_ACCESS_KEY = 'AWS_SECRET_ACCESS_KEY';

    /**
     * Values that are a default wherever they appear, independently of what any
     * sample file says. These are what a hurried operator types when a field
     * has to be filled in before the stack will start.
     */
    public const UNIVERSAL_PLACEHOLDERS = [
        'change me',
        'changeme',
        'change-me',
        'change_me',
        'changethis',
        'change-this',
        'example',
        'password',
        'placeholder',
        'secret',
        'todo',
        'your-secret-here',
    ];

    /**
     * @var list<SecretRequirement>|null
     */
    private ?array $requirements = null;

    /**
     * @return list<SecretRequirement>
     */
    public function requirements(): array
    {
        return $this->requirements ??= [
            new SecretRequirement(
                name: self::APP_KEY,
                label: 'Application key',
                configKey: 'app.key',
                required: true,
                generatable: true,
                defaultValues: [],
                remedy: 'Generate one with `php artisan meridian:secrets --generate`, or set APP_KEY in this node\'s environment.',
            ),

            // Node keys normally live as database overrides written by first-run
            // setup, so a blank file value is the ordinary state of a configured
            // node and not a fault — which is why this is not `required`. What is
            // a fault is a deployment that shipped a sample private key, and that
            // is what the default-value comparison catches. The absence of node
            // keys altogether is reported by the God Mode attention list and by
            // diagnostics, which can read the node record; the boot refusal
            // cannot, because it runs before the database is known to be there.
            new SecretRequirement(
                name: self::NODE_PRIVATE_KEY,
                label: 'Node private key',
                configKey: 'meridian.node.private_key',
                required: false,
                generatable: true,
                defaultValues: [],
                remedy: 'Generate this node\'s keypair with `php artisan meridian:secrets --generate`. Replacing an existing key orphans every operation this node has signed, so an existing key is never overwritten.',
            ),

            new SecretRequirement(
                name: self::DB_PASSWORD,
                label: 'Database password',
                configKey: 'database.connections.pgsql.password',
                required: false,
                generatable: false,
                // The development sample, in both `apps/server/.env.example` and
                // `deploy/docker/.env.example`. A deployment that kept it is a
                // deployment whose database password is published in this
                // repository.
                defaultValues: ['meridian'],
                remedy: 'Set DB_PASSWORD to the password this node\'s database actually uses. Meridian cannot generate it: the database already has one, and a generated value would only stop the node connecting.',
                applicable: static fn (): bool => config('database.default') === 'pgsql',
            ),

            new SecretRequirement(
                name: self::REDIS_PASSWORD,
                label: 'Redis password',
                configKey: 'database.redis.default.password',
                required: false,
                generatable: false,
                defaultValues: [],
                remedy: 'Set REDIS_PASSWORD to the password this node\'s Redis instance actually uses.',
                applicable: static fn (): bool => in_array('redis', [
                    config('cache.default'),
                    config('queue.default'),
                    config('session.driver'),
                    config('broadcasting.default'),
                ], true),
            ),

            new SecretRequirement(
                name: self::MAIL_PASSWORD,
                label: 'Mail password',
                configKey: 'mail.mailers.smtp.password',
                required: false,
                generatable: false,
                defaultValues: [],
                remedy: 'Set MAIL_PASSWORD to the password this node\'s SMTP account actually uses.',
                applicable: static fn (): bool => config('mail.default') === 'smtp'
                    && trim((string) config('mail.mailers.smtp.username', '')) !== '',
            ),

            new SecretRequirement(
                name: self::GOOGLE_OAUTH_CLIENT_SECRET,
                label: 'Google OAuth client secret',
                configKey: 'meridian.oauth.google.client_secret',
                required: false,
                generatable: false,
                defaultValues: [],
                remedy: 'Set GOOGLE_OAUTH_CLIENT_SECRET to the secret issued with this node\'s Google client ID, or clear GOOGLE_OAUTH_CLIENT_ID to switch Google login off.',
                applicable: static fn (): bool => trim((string) config('meridian.oauth.google.client_id', '')) !== '',
            ),

            new SecretRequirement(
                name: self::DISCORD_OAUTH_CLIENT_SECRET,
                label: 'Discord OAuth client secret',
                configKey: 'meridian.oauth.discord.client_secret',
                required: false,
                generatable: false,
                defaultValues: [],
                remedy: 'Set DISCORD_OAUTH_CLIENT_SECRET to the secret issued with this node\'s Discord client ID, or clear DISCORD_OAUTH_CLIENT_ID to switch Discord login off.',
                applicable: static fn (): bool => trim((string) config('meridian.oauth.discord.client_id', '')) !== '',
            ),

            new SecretRequirement(
                name: self::CHANGELOG_TOKEN,
                label: 'Changelog refresh token',
                configKey: 'meridian.changelog.refresh.token',
                required: false,
                generatable: false,
                defaultValues: [],
                remedy: 'Set MERIDIAN_CHANGELOG_TOKEN to a read-only source-repository token, or clear it to leave the packaged changelog unrefreshed.',
                applicable: static fn (): bool => trim((string) config('meridian.changelog.refresh.token', '')) !== '',
            ),

            new SecretRequirement(
                name: self::AWS_SECRET_ACCESS_KEY,
                label: 'Object storage secret access key',
                configKey: 'filesystems.disks.s3.secret',
                required: false,
                generatable: false,
                defaultValues: [],
                remedy: 'Set AWS_SECRET_ACCESS_KEY to the secret issued with this node\'s access key ID, or move FILESYSTEM_DISK off s3.',
                applicable: static fn (): bool => config('filesystems.default') === 's3'
                    || trim((string) config('filesystems.disks.s3.key', '')) !== '',
            ),
        ];
    }

    /**
     * The requirements this node is actually subject to.
     *
     * @return list<SecretRequirement>
     */
    public function applicable(): array
    {
        return array_values(array_filter(
            $this->requirements(),
            static fn (SecretRequirement $requirement): bool => $requirement->applies(),
        ));
    }

    public function requirement(string $name): ?SecretRequirement
    {
        foreach ($this->requirements() as $requirement) {
            if ($requirement->name === $name) {
                return $requirement;
            }
        }

        return null;
    }
}
