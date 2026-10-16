<?php

namespace Odden\SesTenants\Transport;

use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Exceptions\SendFailed;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Stands between an owner's mail and SES. Every message is checked against the owner's verified domains (the From
 * address and the envelope sender must be on one, exactly: a subdomain another owner holds is not theirs). A message
 * can't name its own SES tenant: any such header is removed, and the transport behind this one sets the owner's own.
 * Anything else is refused, never sent from somewhere else.
 */
final class GuardedTransport implements TransportInterface
{
    /**
     * @param  list<string>  $domains  The owner's verified sending domains.
     */
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly array $domains,
        private readonly ?SendGate $gate = null,
        private readonly int|string|null $ownerId = null,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! $message instanceof Email) {
            throw new SendFailed('Only a complete message can be sent.');
        }

        $from = array_map(fn ($address): string => $address->getAddress(), $message->getFrom());

        if ($from === []) {
            throw new SendFailed('Mail can only be sent from a verified sending domain, so nothing was sent.');
        }

        // Every address the message could be sent or bounced as, not only the visible From.
        $addresses = array_filter([...$from, $message->getSender()?->getAddress(), $message->getReturnPath()?->getAddress(), $envelope?->getSender()->getAddress()]);

        foreach ($addresses as $address) {
            if (! $this->allowed($address)) {
                throw new SendFailed('Mail can only be sent from a verified sending domain, so nothing was sent.');
            }
        }

        // The message can't choose which owner it is attributed to (the transport behind this sets it).
        $message->getHeaders()->remove('X-SES-TENANT-NAME');

        $recipients = max(1, count($envelope?->getRecipients() ?? Envelope::create($message)->getRecipients()));
        $counted = $this->gate !== null && $this->ownerId !== null;

        $counted && $this->gate->before($this->ownerId, $recipients);

        try {
            $sent = $this->inner->send($message, $envelope);
        } catch (SendFailed $e) {
            throw $e;
        } catch (TransportException $e) {
            throw new SendFailed($e->getMessage(), (int) $e->getCode(), $e);
        }

        $counted && $this->gate->after($this->ownerId, $recipients, substr((string) strrchr($from[0], '@'), 1));

        return $sent;
    }

    private function allowed(string $address): bool
    {
        $at = strrpos($address, '@');

        return $at !== false && in_array(strtolower(substr($address, $at + 1)), $this->domains, true);
    }

    public function __toString(): string
    {
        return 'ses-tenants://guarded';
    }
}
