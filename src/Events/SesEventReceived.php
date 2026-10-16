<?php

namespace Odden\SesTenants\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A verified SES delivery event for an owner's message. Apply it: suppress a hard-bounced or complaining address, count a
 * delivery. The package has already counted it in its own usage.
 */
final readonly class SesEventReceived
{
    /**
     * @param  'delivery'|'bounce'|'complaint'  $type
     * @param  array<string, mixed>  $event  The decoded SES event.
     */
    public function __construct(public Model $owner, public string $type, public array $event, public bool $permanentBounce = false) {}
}
