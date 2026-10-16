<?php

namespace Odden\SesTenants\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's sending was paused. Source: rates (its own bounce or complaint rates), ses (SES stopped it) or manual.
 */
final readonly class SendingPaused
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public Model $owner, public string $reason, public string $source, public array $details = []) {}
}
