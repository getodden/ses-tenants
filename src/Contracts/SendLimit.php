<?php

namespace Odden\SesTenants\Contracts;

use Illuminate\Database\Eloquent\Model;
use Odden\SesTenants\Exceptions\SendFailed;

/**
 * A limit the application puts on an owner's sending: a plan, a monthly allowance, a trial. Checked before every send,
 * after the package's own checks (paused, send rate). Bind implementations in config('ses-tenants.limits').
 */
interface SendLimit
{
    /**
     * @throws SendFailed with a reason that says what to do
     */
    public function check(Model $owner, int $recipients): void;
}
