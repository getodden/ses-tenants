<?php

namespace Odden\SesTenants\Sns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Odden\SesTenants\Contracts\ResolvesEventOwner;
use Odden\SesTenants\Contracts\SesOwner;
use Odden\SesTenants\Events\SesEventReceived;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Reputation\Usage;

/**
 * An SES delivery event (bounce, complaint, delivery) that SNS passed on, applied to the owner that sent the message.
 *
 * The owner is the one that holds the sending domain the message was sent from (a domain belongs to one owner, and SES only
 * delivers events for the application's own configuration set), never taken from an id in the message. An event for a domain
 * nobody holds any more is dropped.
 */
class SesEventProcessor
{
    /** @var list<string> */
    private const array HANDLED = ['bounce', 'complaint', 'delivery'];

    public function __construct(private readonly Usage $usage, private readonly Reputation $reputation) {}

    /**
     * @param  array<string, mixed>  $event  The decoded SES event (the "Message" of the SNS notification).
     * @return string What happened: processed, ignored (not a type acted on) or dropped (no owner holds the domain).
     */
    public function handle(array $event): string
    {
        $type = strtolower((string) ($event['eventType'] ?? ($event['notificationType'] ?? '')));

        if (! in_array($type, self::HANDLED, true)) {
            return 'ignored';
        }

        $domain = $this->senderDomain($event);
        $held = $domain === null ? null : SendingDomain::query()->where('domain', $domain)->first();
        $owner = $held?->owner;
        $counted = $owner instanceof Model && $owner instanceof SesOwner;

        if (! $counted) {
            $owner = $domain === null ? null : $this->resolver()?->forDomain($domain);
        }

        if (! $owner instanceof Model) {
            Log::info('Dropped an SES event: no owner holds its sending domain.', ['domain' => $domain, 'type' => $type]);

            return 'dropped';
        }

        $named = $this->sesTenantTag($event);

        // The event says it is for another SES tenant than the domain's owner: don't guess, drop it.
        if ($counted && $named !== null && $named !== $owner->sesTenantName()) {
            Log::warning('Dropped an SES event: its tenant does not match its sending domain.', ['domain' => $domain, 'tenant' => $named]);

            return 'dropped';
        }

        // Hard bounces only: SES retries a soft one itself.
        $permanent = $type === 'bounce' && strtolower((string) ($event['bounce']['bounceType'] ?? '')) === 'permanent';

        event(new SesEventReceived($owner, $type, $event, $permanent));

        $column = match (true) {
            $type === 'delivery' => 'delivered',
            $type === 'complaint' => 'complained',
            $permanent => 'bounced',
            default => null,
        };

        // An owner that sends through another route is not counted: its events change its suppressions and nothing else.
        if ($column !== null && $counted) {
            $this->usage->record($owner, $column);
            $this->reputation->evaluate($owner);
        }

        return 'processed';
    }

    private function resolver(): ?ResolvesEventOwner
    {
        return app()->bound(ResolvesEventOwner::class) ? app(ResolvesEventOwner::class) : null;
    }

    /**
     * The domain the message was sent from.
     *
     * @param  array<string, mixed>  $event
     */
    private function senderDomain(array $event): ?string
    {
        $mail = (array) ($event['mail'] ?? []);
        $from = $mail['commonHeaders']['from'][0] ?? ($mail['source'] ?? null);

        if (! is_string($from) || preg_match('/@([A-Za-z0-9.-]+)>?\s*$/', trim($from), $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * The SES tenant name the event carries, when it carries one.
     *
     * @param  array<string, mixed>  $event
     */
    private function sesTenantTag(array $event): ?string
    {
        $tag = ((array) ($event['mail']['tags'] ?? []))['ses:tenant'] ?? null;
        $tag = is_array($tag) ? ($tag[0] ?? null) : $tag;

        return is_string($tag) && $tag !== '' ? $tag : null;
    }
}
