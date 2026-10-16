<?php

namespace Odden\SesTenants;

use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Odden\SesTenants\Contracts\NullSendGate;
use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Domains\DomainPolicy;
use Odden\SesTenants\Identities\NullIdentities;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Identities\SesV2Identities;
use Odden\SesTenants\Sns\SnsVerifier;
use Odden\SesTenants\Transport\TransportFactory;

class SesTenantsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ses-tenants.php', 'ses-tenants');

        $this->app->singleton(SesV2Client::class, fn (): SesV2Client => new SesV2Client($this->awsOptions()));
        $this->app->singleton(StsClient::class, fn (): StsClient => new StsClient($this->awsOptions()));

        $this->app->singleton(SesIdentities::class, function ($app): SesIdentities {
            if (! (bool) $app['config']->get('ses-tenants.enabled')) {
                return new NullIdentities;
            }

            return new SesV2Identities(
                $app->make(SesV2Client::class),
                $app['config']->get('ses-tenants.configuration_set') ?: null,
                $app->make(StsClient::class),
            );
        });

        $this->app->singleton(SnsVerifier::class, function ($app): SnsVerifier {
            $config = (array) $app['config']->get('ses-tenants.sns');

            return new SnsVerifier(
                array_values(array_map('strval', (array) ($config['topics'] ?? []))),
                (int) ($config['max_age_seconds'] ?? 86400),
                (bool) ($config['require_signature_v2'] ?? false),
                (bool) ($config['verify_chain'] ?? true),
                ($config['chain_roots'] ?? null) ?: null,
                array_values(array_map('strval', (array) ($config['chain_issuer_hosts'] ?? ['amazontrust.com', 'digicert.com']))),
            );
        });

        $this->app->singleton(DomainPolicy::class, fn ($app): DomainPolicy => new DomainPolicy(
            array_values(array_map('strval', (array) $app['config']->get('ses-tenants.reserved_domains', []))),
        ));

        $this->app->singletonIf(SendGate::class, NullSendGate::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/ses-tenants.php' => config_path('ses-tenants.php')], 'ses-tenants-config');

        Mail::extend('ses-tenants', fn (array $config) => $this->app->make(TransportFactory::class)->make($config));
    }

    /**
     * @return array<string, mixed>
     */
    private function awsOptions(): array
    {
        $config = $this->app->make('config');
        $options = ['version' => 'latest', 'region' => (string) ($config->get('ses-tenants.region') ?: 'us-east-1')];

        if (filled($config->get('ses-tenants.key')) && filled($config->get('ses-tenants.secret'))) {
            $options['credentials'] = ['key' => (string) $config->get('ses-tenants.key'), 'secret' => (string) $config->get('ses-tenants.secret')];
        }

        return $options;
    }
}
