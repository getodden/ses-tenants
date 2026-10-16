<?php

use Illuminate\Support\Facades\Event;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\ReputationWarning;
use Odden\SesTenants\Events\SendingPaused;
use Odden\SesTenants\Events\SendingResumed;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Identities\TenantSendingStatus;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Reputation\Usage;
use Odden\SesTenants\Reputation\Warmup;

function usage(object $owner, int $sent, int $bounced = 0, int $complained = 0): void
{
    $usage = app(Usage::class);
    $usage->record($owner, 'sent', $sent);
    $bounced > 0 && $usage->record($owner, 'bounced', $bounced);
    $complained > 0 && $usage->record($owner, 'complained', $complained);
}

it('counts nothing until the owner has sent enough for a rate to mean anything', function (): void {
    $owner = $this->owner();
    usage($owner, 400, 100);

    expect(app(Reputation::class)->rates($owner)['counted'])->toBeFalse()->and(app(Reputation::class)->evaluate($owner))->toBe('ok');
});

it('warns once between the lines, and not again within the window', function (): void {
    Event::fake([ReputationWarning::class, SendingPaused::class]);
    $owner = $this->owner();
    usage($owner, 1000, 25); // 2.5%: over the 2% warning, under the 4% pause

    expect(app(Reputation::class)->evaluate($owner))->toBe('warned')->and(app(Reputation::class)->evaluate($owner))->toBe('warned');
    Event::assertDispatchedTimes(ReputationWarning::class, 1);
    Event::assertNotDispatched(SendingPaused::class);
});

it('pauses at the higher line, in the database and at SES, and says why', function (): void {
    Event::fake([SendingPaused::class]);
    $owner = $this->owner();
    usage($owner, 1000, 50); // 5%

    expect(app(Reputation::class)->evaluate($owner))->toBe('paused')->and(app(Reputation::class)->isPaused($owner))->toBeTrue()
        ->and($this->ses->sendingChanges)->toBe([['owner-1', false]]);
    Event::assertDispatched(SendingPaused::class, fn ($e) => $e->source === 'rates' && str_contains($e->reason, 'Hard bounces were 5.0%'));
});

it('pauses on complaints too', function (): void {
    $owner = $this->owner();
    usage($owner, 1000, 0, 2); // 0.2%

    expect(app(Reputation::class)->evaluate($owner))->toBe('paused')->and(app(Reputation::class)->status($owner)->pause_reason)->toContain('Complaints were 0.20%');
});

it('judges a warming-up domain by the tighter limits', function (): void {
    $owner = $this->owner();
    $domain = app(SendingDomains::class)->add($owner, 'mail.example.com');
    $this->ses->passes('mail.example.com');
    app(SendingDomains::class)->check($domain);
    app(Warmup::class)->record($domain->fresh(), 1);
    usage($owner, 150, 4); // 2.7%: warns under the usual limits' 4% pause, pauses at the warm-up 2%

    expect(app(Reputation::class)->evaluate($owner))->toBe('paused');
});

it('resumes only when SES allows it, and counts the rates from that day', function (): void {
    Event::fake([SendingResumed::class]);
    $owner = $this->owner();
    usage($owner, 1000, 50);
    app(Reputation::class)->evaluate($owner);

    // SES itself still holds the owner stopped (a pause the application cannot lift).
    $this->ses->tenantStatuses['owner-1'] = new TenantSendingStatus('DISABLED', 'DISABLED', 'high bounce rate', 'ENABLED', null, null);
    expect(fn () => app(Reputation::class)->resume($owner))->toThrow(SesTenantsException::class, 'high bounce rate')
        ->and(app(Reputation::class)->isPaused($owner))->toBeTrue();

    $this->ses->tenantStatuses = [];
    expect(app(Reputation::class)->resume($owner))->toBeTrue()->and(app(Reputation::class)->isPaused($owner))->toBeFalse()
        ->and(app(Reputation::class)->resume($owner))->toBeFalse()
        ->and(app(Reputation::class)->rates($owner)['from']->toDateString())->toBe(now()->toDateString());
    Event::assertDispatched(SendingResumed::class, fn ($e) => $e->source === 'manual');
});

it('follows SES: pauses an owner SES stopped, and lifts a pause SES lifted, never one of its own', function (): void {
    Event::fake([SendingPaused::class, SendingResumed::class]);
    $owner = $this->owner();
    $reputation = app(Reputation::class);

    $this->ses->tenantStatuses['owner-1'] = new TenantSendingStatus('DISABLED', 'DISABLED', 'reputation findings', 'ENABLED', null, 'HIGH');
    $reputation->syncWithSes($owner);

    expect($reputation->isPaused($owner))->toBeTrue()->and($reputation->status($owner)->pause_source)->toBe('ses')
        ->and($this->ses->sendingChanges)->toBe([]); // an SES pause needs no echo
    Event::assertDispatched(SendingPaused::class, fn ($e) => $e->source === 'ses');

    $this->ses->tenantStatuses = [];
    $reputation->syncWithSes($owner);

    expect($reputation->isPaused($owner))->toBeFalse();
    Event::assertDispatched(SendingResumed::class, fn ($e) => $e->source === 'ses');

    usage($owner, 1000, 50);
    $reputation->evaluate($owner); // paused by its own rates
    $reputation->syncWithSes($owner);

    expect($reputation->isPaused($owner))->toBeTrue();
});

it('sums the whole account\'s usage across owners', function (): void {
    usage($this->owner('a'), 100, 1);
    usage($this->owner('b'), 50, 2);

    expect(app(Usage::class)->accountTotals(now()->startOfDay()))->toBe(['sent' => 150, 'delivered' => 0, 'bounced' => 3, 'complained' => 0]);
});
