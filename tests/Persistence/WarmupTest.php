<?php

use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Warmup;

function warmingDomain(object $test, int $daysSinceFirstSend = 0): SendingDomain
{
    $record = app(SendingDomains::class)->add($test->owner(), 'mail.example.com');
    $test->ses->passes('mail.example.com');
    app(SendingDomains::class)->check($record);
    $record->forceFill(['first_sent_on' => now()->subDays($daysSinceFirstSend)->toDateString()])->save();

    return $record->fresh();
}

it('limits a domain\'s first days to a ceiling that steps up, then stops limiting it', function (): void {
    $warmup = app(Warmup::class);

    expect($warmup->ceiling(warmingDomain($this, 0)))->toBe(200);

    $domain = SendingDomain::query()->first();
    $domain->forceFill(['first_sent_on' => now()->subDays(1)->toDateString()])->save();
    expect($warmup->ceiling($domain->fresh()))->toBe(500);

    $domain->forceFill(['first_sent_on' => now()->subDays(6)->toDateString()])->save();
    expect($warmup->ceiling($domain->fresh()))->toBe(20000);

    $domain->forceFill(['first_sent_on' => now()->subDays(7)->toDateString()])->save();
    expect($warmup->ceiling($domain->fresh()))->toBeNull()->and($warmup->step($domain->fresh()))->toBeNull();
});

it('starts counting from the first send, and counts what a domain sends today', function (): void {
    $domain = app(SendingDomains::class)->add($this->owner(), 'mail.example.com');
    $warmup = app(Warmup::class);

    expect($domain->first_sent_on)->toBeNull();

    $warmup->record($domain, 40);
    $warmup->record($domain->fresh(), 2);

    expect($warmup->sentToday($domain))->toBe(42)->and($domain->fresh()->first_sent_on->toDateString())->toBe(now()->toDateString());
});

it('can be skipped, started further along, or switched off', function (): void {
    $warmup = app(Warmup::class);
    $domain = warmingDomain($this, 0);

    $domain->forceFill(['warmup_start_step' => 3])->save();
    expect($warmup->ceiling($domain->fresh()))->toBe(2000);

    $domain->forceFill(['warmup_skipped_at' => now()])->save();
    expect($warmup->ceiling($domain->fresh()))->toBeNull();

    $domain->forceFill(['warmup_skipped_at' => null, 'warmup_start_step' => 0])->save();
    config(['ses-tenants.warmup.enabled' => false]);
    expect($warmup->ceiling($domain->fresh()))->toBeNull()->and($warmup->isWarming($domain->owner))->toBeFalse();
});

it('says whether an owner is warming up, once a verified domain has started sending', function (): void {
    $warmup = app(Warmup::class);
    $domain = app(SendingDomains::class)->add($this->owner(), 'mail.example.com');

    expect($warmup->isWarming($domain->owner))->toBeFalse(); // not verified, not sending

    $this->ses->passes('mail.example.com');
    app(SendingDomains::class)->check($domain);
    expect($warmup->isWarming($domain->owner))->toBeFalse(); // verified, has not sent

    $warmup->record($domain->fresh(), 1);
    expect($warmup->isWarming($domain->owner))->toBeTrue()->and($warmup->note($domain->fresh()))->toContain('Warming up: day 1 of 7');
});

it('names the next window after midnight, spread over a quarter of an hour', function (): void {
    $next = app(Warmup::class)->nextWindow();

    expect($next->isAfter(now()->endOfDay()))->toBeTrue()->and($next->lt(now()->addDay()->startOfDay()->addSeconds(901)))->toBeTrue();
});
