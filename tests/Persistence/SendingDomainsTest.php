<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Odden\SesTenants\Domains\DomainPolicy;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\DomainChanged;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Identities\IdentityState;
use Odden\SesTenants\Models\SendingDomain;

function domains(): SendingDomains
{
    return app(SendingDomains::class);
}

it('adds a domain: claims the name, creates the identity, attaches it to the owner\'s SES tenant and lists the DNS records', function (): void {
    Event::fake([DomainChanged::class]);
    $owner = $this->owner();

    $record = domains()->add($owner, 'Mail.Example.com');

    expect($record->domain)->toBe('mail.example.com')->and($record->status)->toBe(SendingDomain::PENDING)
        ->and($record->records)->toHaveCount(6)
        ->and($record->records[3])->toMatchArray(['type' => 'MX', 'name' => 'bounce.mail.example.com', 'value' => '10 feedback-smtp.us-east-2.amazonses.com'])
        ->and($this->ses->tenants)->toBe(['owner-1' => ['mail.example.com']])
        ->and($this->ses->policies)->toBe(['owner-1' => 'strict']); // a new domain is inside its warm-up
    Event::assertDispatched(DomainChanged::class, fn ($e) => $e->change === 'added' && $e->domain->is($record));
});

it('refuses a name the policy refuses, a second claim, and more than the owner\'s limit', function (): void {
    $owner = $this->owner();
    $other = $this->owner('other');
    config(['ses-tenants.domains.max_per_owner' => 2]);

    expect(fn () => domains()->add($owner, 'gmail.com'))->toThrow(SesTenantsException::class, 'free-mail');

    domains()->add($owner, 'a.example.com');
    domains()->add($owner, 'b.example.com');

    expect(fn () => domains()->add($other, 'a.example.com'))->toThrow(SesTenantsException::class, 'already in use')
        ->and(fn () => domains()->add($owner, 'c.example.com'))->toThrow(SesTenantsException::class, 'limit of 2');
});

it('limits how fast domains can be added', function (): void {
    $owner = $this->owner();
    config(['ses-tenants.domains.max_per_owner' => 100]);

    for ($i = 0; $i < 10; $i++) {
        domains()->add($owner, "d{$i}.example.com");
    }

    expect(fn () => domains()->add($owner, 'd10.example.com'))->toThrow(SesTenantsException::class, 'Too many');
    RateLimiter::clear('ses-tenants:domain-adds:'.$owner->getMorphClass().':'.$owner->getKey());
});

it('keeps nothing half made when SES refuses: the row and the identity both go', function (): void {
    $owner = $this->owner();
    $this->ses->failAttach = true;

    expect(fn () => domains()->add($owner, 'mail.example.com'))->toThrow(SesTenantsException::class);
    expect(SendingDomain::query()->count())->toBe(0)->and($this->ses->identities)->toBe([]);
});

it('moves a domain to verified when SES says the records pass, and tells nobody twice', function (): void {
    Event::fake([DomainChanged::class]);
    $record = domains()->add($this->owner(), 'mail.example.com');

    expect(domains()->check($record))->toBeFalse()->and($record->fresh()->status)->toBe(SendingDomain::PENDING)
        ->and($record->fresh()->last_error)->toBe('Waiting for the DKIM records.');

    $this->ses->passes('mail.example.com');
    domains()->check($record);
    domains()->check($record->fresh());

    expect($record->fresh()->status)->toBe(SendingDomain::VERIFIED)->and($record->fresh()->verified_at)->not->toBeNull();
    Event::assertDispatchedTimes(DomainChanged::class, 2); // added, verified
});

it('fails a verified domain that stops passing and says so; fixing it brings it back', function (): void {
    $record = domains()->add($this->owner(), 'mail.example.com');
    $this->ses->passes('mail.example.com');
    domains()->check($record);

    $this->ses->identities['mail.example.com'] = new IdentityState(['a', 'b', 'c'], 'FAILED', 'SUCCESS', false);

    expect(domains()->check($record->fresh()))->toBeTrue()->and($record->fresh()->status)->toBe(SendingDomain::FAILED);

    $this->ses->passes('mail.example.com');
    domains()->check($record->fresh());

    expect($record->fresh()->status)->toBe(SendingDomain::VERIFIED);
});

it('fails a domain whose identity SES no longer has, and leaves it alone while SES cannot be reached', function (): void {
    $record = domains()->add($this->owner(), 'mail.example.com');
    $this->ses->passes('mail.example.com');
    domains()->check($record);

    $this->ses->unreachable = true;
    expect(domains()->check($record->fresh()))->toBeFalse()->and($record->fresh()->status)->toBe(SendingDomain::VERIFIED);

    $this->ses->unreachable = false;
    unset($this->ses->identities['mail.example.com']);

    expect(domains()->check($record->fresh()))->toBeTrue()->and($record->fresh()->status)->toBe(SendingDomain::FAILED)
        ->and($record->fresh()->last_error)->toContain('no longer exists');
});

it('removes a domain at SES first, and keeps the row when SES will not let go', function (): void {
    $owner = $this->owner();
    $record = domains()->add($owner, 'mail.example.com');

    $this->ses->failDelete = true;
    expect(domains()->remove($record))->toBeFalse()->and(SendingDomain::query()->count())->toBe(1);

    $this->ses->failDelete = false;
    expect(domains()->remove($record->fresh()))->toBeTrue()->and(SendingDomain::query()->count())->toBe(0)
        ->and($this->ses->deleted)->toBe(['mail.example.com'])
        ->and($this->ses->detached)->toContain(['owner-1', 'mail.example.com'])
        ->and($this->ses->tenants)->not->toHaveKey('owner-1');
});

it('drops a claim that was never verified and has outlived its wait, never a verified one', function (): void {
    $owner = $this->owner();
    $stale = domains()->add($owner, 'stale.example.com');
    $fresh = domains()->add($owner, 'fresh.example.com');
    $kept = domains()->add($owner, 'kept.example.com');
    $this->ses->passes('kept.example.com');
    domains()->check($kept);

    $stale->forceFill(['created_at' => now()->subDays(8)])->save();
    $kept->forceFill(['created_at' => now()->subDays(8)])->save();
    $fresh->forceFill(['created_at' => now()->subDays(1)])->save();

    expect(domains()->dropUnverified($fresh->fresh()))->toBeFalse()
        ->and(domains()->dropUnverified($stale->fresh()))->toBeTrue()
        ->and(domains()->dropUnverified($kept->fresh()))->toBeFalse()
        ->and(SendingDomain::query()->pluck('domain')->sort()->values()->all())->toBe(['fresh.example.com', 'kept.example.com']);
});

it('adopts an application-owned domain that already has an identity, and never deletes it', function (): void {
    $owner = $this->owner('platform');
    config(['ses-tenants.adopt' => [(string) $owner->getKey() => ['own.example.com']], 'ses-tenants.reserved_domains' => ['example.com']]);
    app()->forgetInstance(DomainPolicy::class);
    app()->forgetInstance(SendingDomains::class);
    $this->ses->passes('own.example.com');

    $record = domains()->add($owner, 'own.example.com');

    expect($this->ses->identities)->toHaveKey('own.example.com')->and($record->records)->not->toBeNull();

    // Another owner can never take it, and removing it lets go without deleting the identity at SES.
    expect(fn () => domains()->add($this->owner('x'), 'own.example.com'))->toThrow(SesTenantsException::class);
    expect(domains()->remove($record))->toBeTrue()->and($this->ses->deleted)->toBe([])->and($this->ses->identities)->toHaveKey('own.example.com');
});

it('sets the stricter reputation policy while the owner is risky or a domain is warming up, and the normal one after', function (): void {
    $owner = $this->owner();
    $owner->risky = true;
    $record = domains()->add($owner, 'mail.example.com');

    expect($this->ses->policies['owner-1'])->toBe('strict');

    $owner->risky = false;
    domains()->syncPolicy($owner);
    expect($this->ses->policies['owner-1'])->toBe('strict'); // a domain that has not sent yet is on step 0

    $record->forceFill(['warmup_skipped_at' => now()])->save();
    domains()->syncPolicy($owner);

    expect($this->ses->policies['owner-1'])->toBe('standard');
});

it('leaves a policy failure for the next check and does not fail the add', function (): void {
    $this->ses->failPolicy = true;

    $record = domains()->add($this->owner(), 'mail.example.com');

    expect($record->exists)->toBeTrue()->and(domains()->status($record->owner)->ses_policy)->toBeNull();
});

it('lets go of everything an owner holds when it is deleted, and records what SES would not release', function (): void {
    $owner = $this->owner();
    domains()->add($owner, 'a.example.com');
    domains()->add($owner, 'b.example.com');
    $this->ses->failDelete = true;

    domains()->forgetOwner($owner);

    expect(SendingDomain::query()->count())->toBe(0)
        ->and(DB::table('ses_orphaned_identities')->pluck('tenant_name', 'domain')->all())->toBe(['a.example.com' => 'owner-1', 'b.example.com' => 'owner-1']);

    $this->ses->failDelete = false;
    $this->artisan('ses-tenants:release-orphans')->assertSuccessful();

    expect(DB::table('ses_orphaned_identities')->count())->toBe(0)->and($this->ses->deleted)->toContain('a.example.com', 'b.example.com');
});
