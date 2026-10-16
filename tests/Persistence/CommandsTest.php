<?php

use Illuminate\Support\Facades\Event;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\DomainStoppedWorking;
use Odden\SesTenants\Identities\IdentityState;
use Odden\SesTenants\Models\OwnerStatus;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Reputation;

it('checks the domains that are due and says when a verified one stopped working', function (): void {
    Event::fake([DomainStoppedWorking::class]);
    $owner = $this->owner();
    $service = app(SendingDomains::class);
    $verified = $service->add($owner, 'ok.example.com');
    $broken = $service->add($owner, 'broken.example.com');
    $this->ses->passes('ok.example.com');
    $this->ses->passes('broken.example.com');
    $service->check($verified);
    $service->check($broken);
    SendingDomain::query()->update(['last_checked_at' => now()->subDays(2)]);
    $this->ses->identities['broken.example.com'] = new IdentityState(['a', 'b', 'c'], 'FAILED', 'SUCCESS', false);

    $this->artisan('ses-tenants:check-domains')->expectsOutputToContain('Checked 2 sending domain(s); dropped 0 unverified.')->assertSuccessful();

    Event::assertDispatched(DomainStoppedWorking::class, fn ($e) => $e->domain->domain === 'broken.example.com');
    expect(SendingDomain::query()->where('domain', 'ok.example.com')->first()->status)->toBe(SendingDomain::VERIFIED);
});

it('does not check a domain that is not due', function (): void {
    $owner = $this->owner();
    $record = app(SendingDomains::class)->add($owner, 'ok.example.com');
    $this->ses->passes('ok.example.com');
    app(SendingDomains::class)->check($record);

    $this->artisan('ses-tenants:check-domains')->expectsOutputToContain('Checked 0 sending domain(s)')->assertSuccessful();
});

it('syncs each owner\'s SES tenant with its domains, policy and pause state', function (): void {
    $owner = $this->owner();
    app(SendingDomains::class)->add($owner, 'mail.example.com');
    $this->ses->tenants = [];
    $this->ses->policies = [];
    OwnerStatus::query()->update(['ses_policy' => null]);

    $this->artisan('ses-tenants:sync')->expectsOutputToContain('Synced 1 of 1')->assertSuccessful();

    expect($this->ses->tenants)->toBe(['owner-1' => ['mail.example.com']])->and($this->ses->policies)->toBe(['owner-1' => 'strict']);
});

it('pauses and resumes an owner by key', function (): void {
    $owner = $this->owner();

    $this->artisan('ses-tenants:pause 1 --reason=Testing')->expectsOutput('Paused.')->assertSuccessful();
    expect(app(Reputation::class)->isPaused($owner))->toBeTrue()->and(app(Reputation::class)->status($owner)->pause_reason)->toBe('Testing');

    $this->artisan('ses-tenants:resume 1')->expectsOutputToContain('Resumed')->assertSuccessful();
    expect(app(Reputation::class)->isPaused($owner))->toBeFalse();

    $this->artisan('ses-tenants:pause 99')->expectsOutputToContain('No owner with key 99')->assertFailed();
});

it('shows and changes a domain\'s warm-up', function (): void {
    $record = app(SendingDomains::class)->add($this->owner(), 'mail.example.com');

    $this->artisan('ses-tenants:warmup mail.example.com')->expectsOutputToContain('step 1 of 7')->assertSuccessful();
    $this->artisan('ses-tenants:warmup mail.example.com --step=3')->expectsOutputToContain('step 4 of 7')->assertSuccessful();
    $this->artisan('ses-tenants:warmup mail.example.com --skip')->expectsOutputToContain('warm-up skipped')->assertSuccessful();
    $this->artisan('ses-tenants:warmup mail.example.com --skip --reset')->expectsOutputToContain('Use one of')->assertFailed();
    $this->artisan('ses-tenants:warmup mail.example.com --step=99')->expectsOutputToContain('a number from 0 to 6')->assertFailed();
    $this->artisan('ses-tenants:warmup nope.example.com')->expectsOutputToContain('No such sending domain')->assertFailed();

    expect($record->fresh()->warmup_skipped_at)->not->toBeNull();
});
