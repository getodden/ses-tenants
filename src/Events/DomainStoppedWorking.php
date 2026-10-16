<?php

namespace Odden\SesTenants\Events;

use Odden\SesTenants\Models\SendingDomain;

/**
 * A verified domain stopped passing (a DNS record went away, or SES lost the identity): it no longer sends. Tell the owner.
 */
final readonly class DomainStoppedWorking
{
    public function __construct(public SendingDomain $domain) {}
}
