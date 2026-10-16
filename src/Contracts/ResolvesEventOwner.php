<?php

namespace Odden\SesTenants\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Optional: names the owner for a sending domain that no SendingDomain row holds, for an owner that sends through SES by
 * another route (its own SMTP credentials, say). Its events then change its suppressions and nothing else: they are not counted
 * toward usage, warm-up or reputation. Bind it in the container.
 */
interface ResolvesEventOwner
{
    public function forDomain(string $domain): ?Model;
}
