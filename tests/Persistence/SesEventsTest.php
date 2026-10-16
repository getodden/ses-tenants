<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Odden\SesTenants\Contracts\ResolvesEventOwner;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\SesEventReceived;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Reputation\Usage;
use Odden\SesTenants\Sns\SesEventProcessor;
use Odden\SesTenants\Sns\SnsVerifier;
use Odden\SesTenants\Tests\Support\TestOwner;

require_once __DIR__.'/../Support/sns-helpers.php';

/** An owner with a verified domain it sends from. */
function senderOwner(object $test, string $domain = 'mail.example.com'): TestOwner
{
    $owner = $test->owner();
    $record = app(SendingDomains::class)->add($owner, $domain);
    $test->ses->passes($domain);
    app(SendingDomains::class)->check($record);

    return $owner;
}

/**
 * @return array<string, mixed>
 */
function sesEvent(string $type, string $from = 'hello@mail.example.com', array $extra = []): array
{
    return [...['eventType' => $type, 'mail' => ['source' => $from, 'commonHeaders' => ['from' => ["Hello <{$from}>"]], 'tags' => []]], ...$extra];
}

it('applies a delivery event to the owner that holds the sending domain, and counts it', function (): void {
    Event::fake([SesEventReceived::class]);
    $owner = senderOwner($this);

    expect(app(SesEventProcessor::class)->handle(sesEvent('Delivery')))->toBe('processed')
        ->and(app(Usage::class)->totals($owner, now()->startOfDay())['delivered'])->toBe(1);
    Event::assertDispatched(SesEventReceived::class, fn ($e) => $e->owner->is($owner) && $e->type === 'delivery' && ! $e->permanentBounce);
});

it('counts only hard bounces, and tells the application about soft ones too', function (): void {
    Event::fake([SesEventReceived::class]);
    $owner = senderOwner($this);
    $processor = app(SesEventProcessor::class);

    $processor->handle(sesEvent('Bounce', extra: ['bounce' => ['bounceType' => 'Transient']]));
    $processor->handle(sesEvent('Bounce', extra: ['bounce' => ['bounceType' => 'Permanent']]));
    $processor->handle(sesEvent('Complaint'));

    expect(app(Usage::class)->totals($owner, now()->startOfDay()))->toMatchArray(['bounced' => 1, 'complained' => 1]);
    Event::assertDispatchedTimes(SesEventReceived::class, 3);
});

it('evaluates the owner\'s reputation after counting, so a rate over the line pauses it', function (): void {
    $owner = senderOwner($this);
    app(Usage::class)->record($owner, 'sent', 1000);
    app(Usage::class)->record($owner, 'bounced', 49);

    app(SesEventProcessor::class)->handle(sesEvent('Bounce', extra: ['bounce' => ['bounceType' => 'Permanent']]));

    expect(app(Reputation::class)->isPaused($owner))->toBeTrue();
});

it('ignores events it does not act on, and drops one for a domain nobody holds', function (): void {
    senderOwner($this);
    $processor = app(SesEventProcessor::class);

    expect($processor->handle(sesEvent('Send')))->toBe('ignored')
        ->and($processor->handle(sesEvent('Delivery', 'x@nobody-holds.example')))->toBe('dropped')
        ->and($processor->handle(['eventType' => 'Delivery']))->toBe('dropped');
});

it('drops an event that names another SES tenant than its domain\'s owner', function (): void {
    senderOwner($this);
    $event = sesEvent('Delivery');
    $event['mail']['tags'] = ['ses:tenant' => ['owner-99']];

    expect(app(SesEventProcessor::class)->handle($event))->toBe('dropped');
});

it('lets an owner that sends by another route be named, without counting it', function (): void {
    Event::fake([SesEventReceived::class]);
    $owner = $this->owner('byo');
    app()->instance(ResolvesEventOwner::class, new class($owner) implements ResolvesEventOwner
    {
        public function __construct(private Model $owner) {}

        public function forDomain(string $domain): ?Model
        {
            return $domain === 'byo.example' ? $this->owner : null;
        }
    });

    expect(app(SesEventProcessor::class)->handle(sesEvent('Delivery', 'a@byo.example')))->toBe('processed')
        ->and(app(Usage::class)->totals($owner, now()->startOfDay())['delivered'])->toBe(0);
    Event::assertDispatched(SesEventReceived::class, fn ($e) => $e->owner->is($owner));
});

/** A signed notification for the controller, with the test certificate trusted. */
function sesNotification(array $event, array $overrides = []): array
{
    [$key, $pem] = snsvCertificate();
    Http::swap(new HttpFactory);
    Http::fake([SNS_TEST_CERT => Http::response($pem), 'https://sns.us-east-2.amazonaws.com/confirm*' => Http::response('ok')]);

    return snsvMessage($key, $overrides + ['Message' => json_encode($event)]);
}

beforeEach(function (): void {
    Cache::flush();
    snsvTrust(snsvAuthority(fresh: true));
    config(['ses-tenants.sns.topics' => [SNS_TEST_TOPIC]]);
    app()->forgetInstance(SnsVerifier::class);
});

it('accepts a verified notification at the events endpoint, once', function (): void {
    $owner = senderOwner($this);
    $message = sesNotification(sesEvent('Delivery'));

    $this->postJson('ses/events', $message)->assertOk()->assertJson(['status' => 'processed']);
    $this->postJson('ses/events', $message)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(app(Usage::class)->totals($owner, now()->startOfDay())['delivered'])->toBe(1);
});

it('refuses what is not verified, and a body that is not a message', function (): void {
    $message = sesNotification(sesEvent('Delivery'));
    $message['Message'] = '{"eventType":"Delivery","tampered":true}';

    $this->postJson('ses/events', $message)->assertForbidden();
    $this->call('POST', 'ses/events', content: 'nope')->assertStatus(400);
});

it('is off without a topic', function (): void {
    config(['ses-tenants.sns.topics' => []]);

    $this->postJson('ses/events', sesNotification(sesEvent('Delivery')))->assertNotFound();
});

it('confirms the subscription only at an Amazon SNS address', function (): void {
    $good = sesNotification([], ['Type' => 'SubscriptionConfirmation', 'Message' => 'x', 'SubscribeURL' => 'https://sns.us-east-2.amazonaws.com/confirm?x=1', 'Token' => 't']);
    $this->postJson('ses/events', $good)->assertOk()->assertJson(['status' => 'confirmed']);

    Cache::flush();
    $bad = sesNotification([], ['Type' => 'SubscriptionConfirmation', 'Message' => 'x', 'SubscribeURL' => 'https://evil.example/confirm', 'Token' => 't']);
    $this->postJson('ses/events', $bad)->assertOk()->assertJson(['status' => 'ignored']);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
});

it('lets a failed event be sent again, instead of answering duplicate', function (): void {
    senderOwner($this);
    $message = sesNotification(sesEvent('Delivery'));
    $calls = 0;
    Event::listen(SesEventReceived::class, function () use (&$calls): void {
        $calls++ === 0 && throw new RuntimeException('hiccup');
    });

    $this->withoutExceptionHandling()->postJson('ses/events', $message);
})->throws(RuntimeException::class);
