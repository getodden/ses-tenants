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
        $app['config']->set('database.default', 'testing');
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
