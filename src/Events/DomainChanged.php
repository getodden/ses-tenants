<?php

namespace Odden\SesTenants\Events;

use Odden\SesTenants\Models\SendingDomain;

/**
 * A sending domain moved: added, adopted (an existing identity taken over as it is), verified, failed, removed or dropped
 * (never verified and abandoned). Record it in an audit log, or tell the owner.
 */
final readonly class DomainChanged
{
    /**
     * @param  'added'|'adopted'|'verified'|'failed'|'removed'|'dropped'  $change
     * @param  array<string, mixed>  $context
     */
    public function __construct(public SendingDomain $domain, public string $change, public array $context = []) {}
}
