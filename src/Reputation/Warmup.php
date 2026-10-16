<?php

namespace Odden\SesTenants\Reputation;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\SesTenants\Models\SendingDomain;

/**
 * A new sending domain has no reputation with the big mailbox providers, and the account's reputation is shared by every
 * owner, so a domain's first days are limited: a daily ceiling that steps up (ses-tenants.warmup.steps), counted from the
 * day it first sent. The package reports the ceiling; the application decides what to do with bulk mail over it (hold it
 * for the next day, say: see {@see self::nextWindow()}). One-to-one mail is never held. A domain can be started further
 * along or skipped.
 */
class Warmup
{
    private const string USAGE = 'ses_domain_usage';

    public function enabled(): bool
    {
        return (bool) config('ses-tenants.warmup.enabled', true) && $this->steps() !== [];
    }

    /**
     * @return list<int>
     */
    public function steps(): array
    {
        return array_values(array_map('intval', (array) config('ses-tenants.warmup.steps', [])));
    }

    /**
     * Which step the domain is on today (0 is the first), or null once it has finished, was skipped, or warm-up is off.
     */
    public function step(SendingDomain $domain): ?int
    {
        if (! $this->enabled() || $domain->warmup_skipped_at !== null) {
            return null;
        }

        $days = $domain->first_sent_on === null ? 0 : (int) $domain->first_sent_on->copy()->startOfDay()->diffInDays(now()->startOfDay());
        $step = (int) $domain->warmup_start_step + max(0, $days);

        return $step < count($this->steps()) ? $step : null;
    }

    /**
     * How many messages the domain may send today, or null when it is not warming up.
     */
    public function ceiling(SendingDomain $domain): ?int
    {
        $step = $this->step($domain);

        return $step === null ? null : $this->steps()[$step];
    }

    public function sentToday(SendingDomain $domain): int
    {
        return (int) DB::table(self::USAGE)->where('sending_domain_id', $domain->getKey())->where('day', now()->toDateString())->value('sent');
    }

    public function record(SendingDomain $domain, int $recipients): void
    {
        if ($recipients < 1) {
            return;
        }

        $day = now()->toDateString();

        DB::table(self::USAGE)->insertOrIgnore(['sending_domain_id' => $domain->getKey(), 'day' => $day, 'created_at' => now(), 'updated_at' => now()]);
        DB::table(self::USAGE)->where('sending_domain_id', $domain->getKey())->where('day', $day)->increment('sent', $recipients, ['updated_at' => now()]);

        if ($domain->first_sent_on === null) {
            $domain->forceFill(['first_sent_on' => $day])->save();
        }
    }

    /**
     * Whether an owner has a verified domain that has started sending and is still inside its warm-up. Its rates are
     * judged more tightly while it is.
     */
    public function isWarming(Model $owner): bool
    {
        return SendingDomain::query()->ownedBy($owner)->where('status', SendingDomain::VERIFIED)->whereNotNull('first_sent_on')->get()
            ->contains(fn (SendingDomain $domain): bool => $this->step($domain) !== null);
    }

    /**
     * When messages held back today could go out: the start of the next day, spread over a quarter of an hour so they do not
     * all arrive in the same second.
     */
    public function nextWindow(): CarbonInterface
    {
        return now()->addDay()->startOfDay()->addSeconds(random_int(5, 900));
    }

    /**
     * What to tell the owner, or null when the domain is not warming up.
     */
    public function note(SendingDomain $domain): ?string
    {
        $step = $this->step($domain);

        if ($step === null) {
            return null;
        }

        return sprintf(
            'Warming up: day %d of %d. Today\'s limit for bulk campaigns is %s messages; %s sent so far. Anything over waits and goes out automatically tomorrow.',
            $step + 1 - (int) $domain->warmup_start_step,
            count($this->steps()) - (int) $domain->warmup_start_step,
            number_format($this->steps()[$step]),
            number_format($this->sentToday($domain)),
        );
    }
}
