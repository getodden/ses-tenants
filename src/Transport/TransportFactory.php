<?php

namespace Odden\SesTenants\Transport;

use Aws\SesV2\SesV2Client;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Odden\SesTenants\Contracts\SendGate;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the "ses-tenants" mailer transport: an owner's mail goes out through the application's SES account, only from
 * the domains it has verified, and always attributed to its own SES tenant.
 *
 * The mailer's config names the owner, so a mailer is made per owner (for example while that owner is active):
 *
 *   'transport' => 'ses-tenants', 'tenant_name' => 'customer-7', 'domains' => ['mail.example.com'], 'owner_id' => 7
 */
final class TransportFactory
{
    /** @var (\Closure(array<string, mixed>): TransportInterface)|null Replaces the transport built from SES, for tests. */
    public static ?\Closure $transportOverride = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, mixed>  $config  tenant_name, domains (verified, exact), owner_id (optional)
     */
    public function make(array $config): TransportInterface
    {
        if (self::$transportOverride !== null) {
            return (self::$transportOverride)($config);
        }

        $client = $this->container->make(SesV2Client::class);
        $configurationSet = $this->container->make(Repository::class)->get('ses-tenants.configuration_set');

        $inner = new TenantSesTransport($client, array_filter(['ConfigurationSetName' => $configurationSet ?: null]), (string) $config['tenant_name']);

        return new GuardedTransport(
            $inner,
            array_values(array_map(fn ($domain): string => strtolower((string) $domain), (array) ($config['domains'] ?? []))),
            $this->container->make(SendGate::class),
            $config['owner_id'] ?? null,
        );
    }
}
