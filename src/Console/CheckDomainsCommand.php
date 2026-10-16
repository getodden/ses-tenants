<?php

namespace Odden\SesTenants\Console;

use Illuminate\Console\Command;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\DomainStoppedWorking;
use Odden\SesTenants\Models\SendingDomain;

/**
 * Asks SES about sending domains: pending ones every run, failed ones hourly (so fixing DNS recovers them), verified ones daily.
 * A verified domain that stops passing is turned off and DomainStoppedWorking is fired. A claim that was never verified is
 * dropped after a while, so squatting on a name does not keep its owner from using it. Schedule it every few minutes.
 */
class CheckDomainsCommand extends Command
{
    protected $signature = 'ses-tenants:check-domains';

    protected $description = "Check sending domains' verification at Amazon SES";

    public function handle(SendingDomains $domains): int
    {
        if (! $domains->enabled()) {
            return self::SUCCESS;
        }

        $due = SendingDomain::query()
            ->where(function ($query): void {
                $query->where('status', SendingDomain::PENDING)
                    ->orWhere(fn ($q) => $q->where('status', SendingDomain::FAILED)->where(fn ($w) => $w->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subHour())))
                    ->orWhere(fn ($q) => $q->where('status', SendingDomain::VERIFIED)->where(fn ($w) => $w->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subDay())));
            })
            ->get();

        foreach ($due as $domain) {
            $domains->check($domain) && event(new DomainStoppedWorking($domain));
        }

        $dropped = SendingDomain::query()
            ->whereNull('verified_at')
            ->where('created_at', '<', now()->subDays((int) config('ses-tenants.domains.drop_unverified_days', 7)))
            ->get()
            ->filter(fn (SendingDomain $domain): bool => $domains->dropUnverified($domain))
            ->count();

        $this->info("Checked {$due->count()} sending domain(s); dropped {$dropped} unverified.");

        return self::SUCCESS;
    }
}
