<?php

namespace Odden\SesTenants\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Odden\SesTenants\Contracts\SesOwner;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Tests\Support\FakeIdentities;
use Odden\SesTenants\Tests\Support\TestOwner;

abstract class PersistentTestCase extends TestCase
{
    public FakeIdentities $ses;

    protected function defineEnvironment($app): void
    {
        $connection = (string) env('SES_TESTS_DB', 'testing');
        $app['config']->set('database.default', $connection);

        // Set SES_TESTS_DB=pgsql or mariadb to run these tests on a server (a throwaway database named by SES_TESTS_DATABASE).
        $app['config']->set('database.connections.pgsql', [...(array) $app['config']->get('database.connections.pgsql'), 'host' => '127.0.0.1', 'port' => 5432, 'username' => 'postgres', 'password' => '', 'database' => env('SES_TESTS_DATABASE', 'ses_tenants_test')]);
        $app['config']->set('database.connections.mariadb', [...(array) $app['config']->get('database.connections.mariadb'), 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'root', 'password' => '', 'database' => env('SES_TESTS_DATABASE', 'ses_tenants_test')]);
        $app['config']->set('ses-tenants.persistence.enabled', true);
        $app['config']->set('ses-tenants.persistence.owner_model', TestOwner::class);
        $app['config']->set('ses-tenants.enabled', true);
        $app['config']->set('ses-tenants.region', 'us-east-2');
        $app['config']->set('ses-tenants.domains.release_wait_ms', 0);
        $app['config']->set('ses-tenants.max_per_second', 1000);
        $app['config']->set('ses-tenants.events.path', 'ses/events');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:wipe')->run();
        $this->artisan('migrate')->run();

        Schema::create('test_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->ses = new FakeIdentities;
        $this->app->instance(SesIdentities::class, $this->ses);
        $this->app->forgetInstance(SendingDomains::class);
        $this->app->forgetInstance(Reputation::class);
    }

    public function owner(string $name = 'acme'): TestOwner&SesOwner
    {
        return TestOwner::query()->create(['name' => $name]);
    }
}
