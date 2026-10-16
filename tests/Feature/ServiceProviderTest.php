<?php

use Illuminate\Support\Facades\Mail;
use Odden\SesTenants\Contracts\NullSendGate;
use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Domains\DomainPolicy;
use Odden\SesTenants\Identities\NullIdentities;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Identities\SesV2Identities;
use Odden\SesTenants\Sns\SnsVerifier;
use Odden\SesTenants\Transport\GuardedTransport;

it('is off until it is switched on: nothing can be created', function (): void {
    expect(app(SesIdentities::class))->toBeInstanceOf(NullIdentities::class)
        ->and(app(SesIdentities::class)->enabled())->toBeFalse();
});

it('uses SES once it is switched on', function (): void {
    config(['ses-tenants.enabled' => true, 'ses-tenants.region' => 'us-east-2', 'ses-tenants.key' => 'k', 'ses-tenants.secret' => 's']);

    expect(app(SesIdentities::class))->toBeInstanceOf(SesV2Identities::class)->and(app(SesIdentities::class)->enabled())->toBeTrue();
});

it('builds the verifier and the domain policy from config', function (): void {
    config(['ses-tenants.sns.topics' => ['arn:aws:sns:us-east-2:123456789012:events'], 'ses-tenants.reserved_domains' => ['example-platform.com']]);

    expect(app(SnsVerifier::class))->toBeInstanceOf(SnsVerifier::class)
        ->and(app(DomainPolicy::class)->problem('mail.example-platform.com'))->toBeString();
});

it('lets the application replace the send gate', function (): void {
    expect(app(SendGate::class))->toBeInstanceOf(NullSendGate::class);

    $gate = new class implements SendGate
    {
        public function before(int|string $ownerId, int $recipients): void {}

        public function after(int|string $ownerId, int $recipients, ?string $domain = null): void {}
    };
    app()->instance(SendGate::class, $gate);

    expect(app(SendGate::class))->toBe($gate);
});

it('registers the ses-tenants mailer transport, guarded to the owner\'s domains', function (): void {
    config([
        'ses-tenants.region' => 'us-east-2', 'ses-tenants.key' => 'k', 'ses-tenants.secret' => 's',
        'mail.mailers.owner' => ['transport' => 'ses-tenants', 'tenant_name' => 'customer-7', 'domains' => ['Mail.Example.com'], 'owner_id' => 7],
    ]);

    $transport = Mail::mailer('owner')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(GuardedTransport::class);
});
