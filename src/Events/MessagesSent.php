<?php

namespace Odden\SesTenants\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Recipients were handed to SES for an owner, and counted. Warn about an allowance, say.
 */
final readonly class MessagesSent
{
    public function __construct(public Model $owner, public int $recipients, public ?string $domain = null) {}
}
