<?php

namespace Odden\SesTenants\Contracts;

use Odden\SesTenants\Exceptions\SendFailed;

/**
 * Asked before every send and told after it. Plans, allowances, pauses and rate limits belong to the application: it
 * implements this and refuses a send by throwing {@see SendFailed} with a reason that says what to do.
 */
interface SendGate
{
    /**
     * @throws SendFailed
     */
    public function before(int|string $ownerId, int $recipients): void;

    public function after(int|string $ownerId, int $recipients, ?string $domain = null): void;
}
