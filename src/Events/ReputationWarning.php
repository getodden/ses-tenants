<?php

namespace Odden\SesTenants\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner's hard-bounce or complaint rate is over the warning line, below the pause line. Tell it to clean its list.
 */
final readonly class ReputationWarning
{
    public function __construct(public Model $owner, public float $bounceRate, public float $complaintRate) {}
}
