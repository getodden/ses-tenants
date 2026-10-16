<?php

namespace Odden\SesTenants;

use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Odden\SesTenants\Console\CheckDomainsCommand;
use Odden\SesTenants\Console\PauseCommand;
use Odden\SesTenants\Console\ReleaseOrphansCommand;
use Odden\SesTenants\Console\ResumeCommand;
use Odden\SesTenants\Console\SyncTenantsCommand;
use Odden\SesTenants\Console\WarmupCommand;
use Odden\SesTenants\Contracts\NullSendGate;
use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Domains\DomainPolicy;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Identities\NullIdentities;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Identities\SesV2Identities;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Reputation\Usage;
use Odden\SesTenants\Reputation\Warmup;
use Odden\SesTenants\Sending\PersistentSendGate;
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

        $this->app->singleton(SendingDomains::class);
        $this->app->singleton(Usage::class);
        $this->app->singleton(Warmup::class);
        $this->app->singleton(Reputation::class);

        // The persistent gate once persistence is on, otherwise one that allows everything. Chosen when it is first asked for, so
        // an application that binds its own SendGate (after this provider) replaces either.
        $this->app->singleton(SendGate::class, fn ($app): SendGate => $this->persistent() ? $app->make(PersistentSendGate::class) : new NullSendGate);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ses-tenants');
        $this->publishes([__DIR__.'/../config/ses-tenants.php' => config_path('ses-tenants.php')], 'ses-tenants-config');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'ses-tenants-migrations');

        if ($this->persistent()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            if (filled(config('ses-tenants.events.path'))) {
                $this->loadRoutesFrom(__DIR__.'/../routes/events.php');
            }

            if ($this->app->runningInConsole()) {
                $this->commands([CheckDomainsCommand::class, ReleaseOrphansCommand::class, SyncTenantsCommand::class, PauseCommand::class, ResumeCommand::class, WarmupCommand::class]);
            }
        }

        Mail::extend('ses-tenants', fn (array $config) => $this->app->make(TransportFactory::class)->make($config));
    }

    private function persistent(): bool
    {
        return (bool) $this->app->make('config')->get('ses-tenants.persistence.enabled');
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
