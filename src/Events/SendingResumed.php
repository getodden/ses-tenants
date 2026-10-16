<?php

namespace Odden\SesTenants\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's pause was lifted: by hand, or because SES allows sending again (source "ses").
 */
final readonly class SendingResumed
{
    public function __construct(public Model $owner, public string $source) {}
}
