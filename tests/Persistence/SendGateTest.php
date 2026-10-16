<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Contracts\SendLimit;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\MessagesSent;
use Odden\SesTenants\Exceptions\SendFailed;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Reputation\Usage;
use Odden\SesTenants\Reputation\Warmup;
use Odden\SesTenants\Sending\PersistentSendGate;

class DenyOverTen implements SendLimit
{
    public function check(Model $owner, int $recipients): void
    {
        $recipients > 10 && throw new SendFailed('Too many for this plan.');
    }
}

it('is the send gate once persistence is on', function (): void {
    expect(app(SendGate::class))->toBeInstanceOf(PersistentSendGate::class);
});

it('counts a send in the owner\'s usage and the domain\'s warm-up, and says so', function (): void {
    Event::fake([MessagesSent::class]);
    $owner = $this->owner();
    $domain = app(SendingDomains::class)->add($owner, 'mail.example.com');

    app(SendGate::class)->before($owner->getKey(), 3);
    app(SendGate::class)->after($owner->getKey(), 3, 'Mail.Example.com');

    expect(app(Usage::class)->totals($owner, now()->startOfDay())['sent'])->toBe(3)
        ->and(app(Warmup::class)->sentToday($domain))->toBe(3)
        ->and($domain->fresh()->first_sent_on)->not->toBeNull();
    Event::assertDispatched(MessagesSent::class, fn ($e) => $e->recipients === 3 && $e->domain === 'Mail.Example.com');
});

it('refuses a paused owner with a reason that says what happened', function (): void {
    $owner = $this->owner();
    app(Reputation::class)->pause($owner, 'Too many bounces.', [], 'manual');

    expect(fn () => app(SendGate::class)->before($owner->getKey(), 1))->toThrow(SendFailed::class, 'paused');
});

it('asks the application\'s own limits, which can refuse', function (): void {
    config(['ses-tenants.limits' => [DenyOverTen::class]]);
    $owner = $this->owner();

    app(SendGate::class)->before($owner->getKey(), 10);

    expect(fn () => app(SendGate::class)->before($owner->getKey(), 11))->toThrow(SendFailed::class, 'Too many for this plan.');
});

it('keeps all owners together under the account send rate', function (): void {
    config(['ses-tenants.max_per_second' => 5, 'ses-tenants.rate_wait_tries' => 1]);
    $owner = $this->owner();

    app(SendGate::class)->before($owner->getKey(), 5);

    expect(fn () => app(SendGate::class)->before($owner->getKey(), 1))->toThrow(SendFailed::class, 'busy');
});

it('does not count a domain it does not know', function (): void {
    $owner = $this->owner();

    app(SendGate::class)->after($owner->getKey(), 2, 'other.example.com');

    expect(app(Usage::class)->totals($owner, now()->startOfDay())['sent'])->toBe(2)->and(SendingDomain::query()->count())->toBe(0);
});
