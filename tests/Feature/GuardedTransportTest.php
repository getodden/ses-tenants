<?php

use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Exceptions\SendFailed;
use Odden\SesTenants\Transport\GuardedTransport;
use Odden\SesTenants\Transport\TenantSesTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class RecordingTransport implements TransportInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public ?Throwable $fail = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }

        $this->sent[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}

class RecordingGate implements SendGate
{
    /** @var list<array<int, mixed>> */
    public array $log = [];

    public ?string $refuse = null;

    public function before(int|string $ownerId, int $recipients): void
    {
        $this->log[] = ['before', $ownerId, $recipients];

        if ($this->refuse !== null) {
            throw new SendFailed($this->refuse);
        }
    }

    public function after(int|string $ownerId, int $recipients, ?string $domain = null): void
    {
        $this->log[] = ['after', $ownerId, $recipients, $domain];
    }
}

function plain(string $from): Email
{
    return (new Email)->from($from)->to('someone@else.test')->subject('Hi')->text('Hello');
}

it('removes any SES tenant header a message carries, so it can not choose its own owner', function (): void {
    $guard = new GuardedTransport($recorder = new RecordingTransport, ['mail.example.com']);
    $message = plain('hello@mail.example.com');
    $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', 'customer-1');

    $guard->send($message);

    expect($recorder->sent)->toHaveCount(1)->and($recorder->sent[0]->getHeaders()->has('X-SES-TENANT-NAME'))->toBeFalse();
});

it('puts the owner\'s own SES tenant in the API call and no tenant header in the message anyone receives', function (): void {
    $calls = [];
    $client = new SesV2Client(['version' => 'latest', 'region' => 'us-east-2', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => new MockHandler([new Result(['MessageId' => 'abc'])])]);
    $client->getHandlerList()->appendInit(function (callable $next) use (&$calls) {
        return function ($command) use ($next, &$calls) {
            $calls[] = $command->toArray();

            return $next($command);
        };
    });
    $transport = new GuardedTransport(new TenantSesTransport($client, ['ConfigurationSetName' => 'shared-set'], 'customer-7'), ['mail.example.com']);

    $message = plain('hello@mail.example.com');
    $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', 'customer-1');
    $transport->send($message);

    expect($calls)->toHaveCount(1)
        ->and($calls[0])->toMatchArray(['TenantName' => 'customer-7', 'ConfigurationSetName' => 'shared-set'])
        ->and($calls[0]['Content']['Raw']['Data'])->not->toContain('X-SES-TENANT-NAME')->not->toContain('customer-1')->not->toContain('customer-7');
});

it('refuses mail that is not from one of the owner\'s verified domains, in any address the message could use', function (): void {
    $guard = new GuardedTransport($recorder = new RecordingTransport, ['mail.example.com']);

    $cases = [
        'from' => plain('x@other.test'),
        'a subdomain another owner may hold' => plain('x@sub.mail.example.com'),
        'sender' => plain('x@mail.example.com')->sender('x@other.test'),
        'return path' => plain('x@mail.example.com')->returnPath('x@other.test'),
        'two froms, one foreign' => (new Email)->from('x@mail.example.com', 'y@other.test')->to('a@b.test')->text('t'),
    ];

    foreach ($cases as $message) {
        expect(fn () => $guard->send($message))->toThrow(SendFailed::class, 'verified sending domain');
    }

    expect(fn () => $guard->send(plain('x@mail.example.com'), new Envelope(new Address('x@other.test'), [new Address('a@b.test')])))->toThrow(SendFailed::class)
        ->and(fn () => $guard->send(new RawMessage('raw')))->toThrow(SendFailed::class)
        ->and($recorder->sent)->toBe([]);
});

it('asks the gate before a send and tells it after, with the recipient count and sending domain', function (): void {
    $gate = new RecordingGate;
    $guard = new GuardedTransport(new RecordingTransport, ['mail.example.com'], $gate, 7);

    $guard->send((new Email)->from('x@mail.example.com')->to('a@b.test', 'c@d.test')->text('t'));

    expect($gate->log)->toBe([['before', 7, 2], ['after', 7, 2, 'mail.example.com']]);
});

it('sends nothing when the gate refuses, and does not report a send', function (): void {
    $gate = new RecordingGate;
    $gate->refuse = 'Paused.';
    $recorder = new RecordingTransport;

    expect(fn () => (new GuardedTransport($recorder, ['mail.example.com'], $gate, 7))->send(plain('x@mail.example.com')))->toThrow(SendFailed::class, 'Paused.')
        ->and($recorder->sent)->toBe([])
        ->and($gate->log)->toBe([['before', 7, 1]]);
});

it('reports a failed send as a send failure and does not count it', function (): void {
    $gate = new RecordingGate;
    $recorder = new RecordingTransport;
    $recorder->fail = new TransportException('AWS SES V2 API failed');

    expect(fn () => (new GuardedTransport($recorder, ['mail.example.com'], $gate, 7))->send(plain('x@mail.example.com')))->toThrow(SendFailed::class, 'AWS SES V2 API failed')
        ->and($gate->log)->toBe([['before', 7, 1]]);
});
