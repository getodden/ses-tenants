<?php

namespace Odden\SesTenants\Sending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Odden\SesTenants\Contracts\SendGate;
use Odden\SesTenants\Contracts\SendLimit;
use Odden\SesTenants\Events\MessagesSent;
use Odden\SesTenants\Exceptions\SendFailed;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Reputation;
use Odden\SesTenants\Reputation\Usage;
use Odden\SesTenants\Reputation\Warmup;

/**
 * Checked before every send and recorded after it: a paused owner is refused, the application's own limits are asked
 * (ses-tenants.limits), and all sends together stay under the account's send rate, so one owner's burst can not starve
 * the rest. After a send its recipients are counted in the owner's usage and the domain's warm-up.
 */
final class PersistentSendGate implements SendGate
{
    public function __construct(
        private readonly Reputation $reputation,
        private readonly Usage $usage,
        private readonly Warmup $warmup,
    ) {}

    /**
     * @throws SendFailed
     */
    public function before(int|string $ownerId, int $recipients): void
    {
        $owner = $this->owner($ownerId);

        if ($this->reputation->isPaused($owner)) {
            throw new SendFailed((string) trans('ses-tenants::messages.paused'));
        }

        foreach ((array) config('ses-tenants.limits', []) as $limit) {
            $limit = is_string($limit) ? app($limit) : $limit;

            if ($limit instanceof SendLimit) {
                $limit->check($owner, $recipients);
            }
        }

        $this->waitForTurn($recipients);
    }

    public function after(int|string $ownerId, int $recipients, ?string $domain = null): void
    {
        $owner = $this->owner($ownerId);

        $this->usage->record($owner, 'sent', $recipients);

        $record = $domain === null ? null : SendingDomain::query()->ownedBy($owner)->where('domain', strtolower($domain))->first();
        $record === null || $this->warmup->record($record, $recipients);

        event(new MessagesSent($owner, $recipients, $domain));
    }

    private function owner(int|string $ownerId): Model
    {
        $class = (string) config('ses-tenants.persistence.owner_model');

        return is_a($class, Model::class, true)
            ? $class::query()->findOrFail($ownerId)
            : throw new SendFailed('No owner model is configured (ses-tenants.persistence.owner_model).');
    }

    /**
     * A turn in the account-wide send rate: counted per second, shared by every worker through the cache.
     *
     * @throws SendFailed
     */
    private function waitForTurn(int $recipients): void
    {
        $max = max(1, (int) config('ses-tenants.max_per_second', 12));
        $weight = min($recipients, $max);

        for ($try = 0; $try < (int) config('ses-tenants.rate_wait_tries', 50); $try++) {
            $key = 'ses-tenants:second:'.now()->getTimestamp();

            if (RateLimiter::increment($key, 3, $weight) <= $max) {
                return;
            }

            RateLimiter::increment($key, 3, -$weight);
            usleep(100_000);
        }

        throw new SendFailed((string) trans('ses-tenants::messages.busy'));
    }
}
