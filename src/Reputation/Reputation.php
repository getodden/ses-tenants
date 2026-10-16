<?php

namespace Odden\SesTenants\Reputation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Odden\SesTenants\Contracts\SesOwner;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Events\ReputationWarning;
use Odden\SesTenants\Events\SendingPaused;
use Odden\SesTenants\Events\SendingResumed;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Models\OwnerStatus;

/**
 * Protects the account's standing with SES, which judges every owner together: an owner's hard-bounce and complaint rates
 * over a rolling window are watched (once it has sent enough for a rate to mean anything), it is warned well before SES's own
 * review levels, and its sending is paused at a higher line. A pause is announced by an event; only the application lifts it
 * (after the owner has cleaned its list), and the rates are then counted from that day on.
 */
class Reputation
{
    public function __construct(
        private readonly Usage $usage,
        private readonly Warmup $warmup,
        private readonly SendingDomains $domains,
    ) {}

    public function status(Model $owner): OwnerStatus
    {
        return $this->domains->status($owner);
    }

    public function isPaused(Model $owner): bool
    {
        return OwnerStatus::query()->ownedBy($owner)->whereNotNull('paused_at')->exists();
    }

    /**
     * @return array{sent: int, bounced: int, complained: int, bounce_rate: float, complaint_rate: float, counted: bool, from: Carbon}
     */
    public function rates(Model $owner): array
    {
        $limits = $this->limits($owner);
        $from = now()->subDays((int) ($limits['window_days'] ?? 7) - 1)->startOfDay();
        $since = OwnerStatus::query()->ownedBy($owner)->value('rates_from');

        if ($since !== null && Carbon::parse($since)->gt($from)) {
            $from = Carbon::parse($since)->startOfDay();
        }

        $totals = $this->usage->totals($owner, $from);
        $sent = $totals['sent'];

        return [
            'sent' => $sent,
            'bounced' => $totals['bounced'],
            'complained' => $totals['complained'],
            'bounce_rate' => $sent > 0 ? $totals['bounced'] / $sent : 0.0,
            'complaint_rate' => $sent > 0 ? $totals['complained'] / $sent : 0.0,
            'counted' => $sent >= (int) ($limits['min_sends'] ?? 500),
            'from' => $from,
        ];
    }

    /**
     * The limits that apply to an owner: the usual ones, or the tighter warm-up ones while a domain is warming up.
     *
     * @return array<string, mixed>
     */
    private function limits(Model $owner): array
    {
        $limits = (array) config('ses-tenants.reputation');

        return $this->warmup->isWarming($owner) ? [...$limits, ...(array) config('ses-tenants.warmup.reputation')] : $limits;
    }

    /**
     * Look at an owner's rates and warn or pause it. Returns where it stands: ok, warned or paused.
     */
    public function evaluate(Model&SesOwner $owner): string
    {
        if ($this->isPaused($owner)) {
            return 'paused';
        }

        $rates = $this->rates($owner);

        if (! $rates['counted']) {
            return 'ok';
        }

        $config = $this->limits($owner);

        if ($rates['bounce_rate'] >= $config['pause_bounce'] || $rates['complaint_rate'] >= $config['pause_complaint']) {
            $this->pause($owner, $rates['bounce_rate'] >= $config['pause_bounce']
                ? sprintf('Hard bounces were %.1f%% of what it sent in the last %d days.', $rates['bounce_rate'] * 100, $config['window_days'])
                : sprintf('Complaints were %.2f%% of what it sent in the last %d days.', $rates['complaint_rate'] * 100, $config['window_days']), $rates);

            return 'paused';
        }

        if ($rates['bounce_rate'] >= $config['warn_bounce'] || $rates['complaint_rate'] >= $config['warn_complaint']) {
            $status = $this->status($owner);

            if ($status->warned_at === null || $status->warned_at->lt(now()->subDays((int) $config['window_days']))) {
                $status->forceFill(['warned_at' => now()])->save();
                DB::afterCommit(fn () => event(new ReputationWarning($owner, $rates['bounce_rate'], $rates['complaint_rate'])));
            }

            return 'warned';
        }

        return 'ok';
    }

    /**
     * Stop an owner's sending (its own numbers, or the application acting at once).
     *
     * @param  array<string, mixed>  $details
     */
    public function pause(Model&SesOwner $owner, string $reason, array $details = [], string $source = 'rates'): void
    {
        $status = $this->status($owner);

        if ($status->isPaused()) {
            return;
        }

        $status->forceFill(['paused_at' => now(), 'pause_reason' => mb_substr($reason, 0, 250), 'pause_source' => $source])->save();

        // The second layer: the owner's SES tenant is paused too, so nothing can send around the package's checks. (A pause SES
        // made needs no echo.) Both that call and the event wait for the transaction around this to commit: if it rolls back
        // they are not made, and a retry does not make them twice. A failure of the SES call never fails the pause recorded.
        if ($source !== 'ses') {
            DB::afterCommit(function () use ($owner): void {
                try {
                    $this->domains->setSending($owner, false);
                } catch (SesTenantsException) {
                    // Reported by the SES wrapper; the pause here already stops sending.
                }
            });
        }

        DB::afterCommit(fn () => event(new SendingPaused($owner, $reason, $source, $details)));
    }

    /**
     * Lift a pause. The owner's rates count from today, so the old numbers don't pause it again at once.
     *
     * @throws SesTenantsException when SES still holds the owner paused
     */
    public function resume(Model&SesOwner $owner): bool
    {
        $status = $this->status($owner);

        if (! $status->isPaused()) {
            return false;
        }

        // SES first: if it still holds the owner paused (a pause it enforced cannot be lifted from here) or cannot be reached, the
        // pause stays, so the application and SES never disagree about an owner that looks resumed.
        $ses = $this->domains->setSending($owner, true);

        if ($ses !== null && $ses->blocked()) {
            throw new SesTenantsException($ses->reason());
        }

        $status->forceFill(['paused_at' => null, 'pause_reason' => null, 'pause_source' => null, 'warned_at' => null, 'rates_from' => now()->toDateString()])->save();
        event(new SendingResumed($owner, 'manual'));

        return true;
    }

    /**
     * Brings the owner's pause into line with its SES tenant. If SES (or someone in its console) has stopped the tenant and the
     * package has not, the owner is paused here too, with SES's reason. If SES paused it and now allows sending, the pause is
     * lifted. Run hourly.
     */
    public function syncWithSes(Model&SesOwner $owner): void
    {
        $ses = $this->domains->sesStatus($owner);

        if ($ses === null) {
            return;
        }

        $status = $this->status($owner);

        if ($ses->blocked() && ! $status->isPaused()) {
            $this->pause($owner, $ses->reason(), ['aws_status' => $ses->awsStatus, 'customer_status' => $ses->customerStatus, 'impact' => $ses->impact], 'ses');
        } elseif (! $ses->blocked() && $status->isPaused() && $status->pause_source === 'ses') {
            $status->forceFill(['paused_at' => null, 'pause_reason' => null, 'pause_source' => null, 'warned_at' => null, 'rates_from' => now()->toDateString()])->save();
            DB::afterCommit(fn () => event(new SendingResumed($owner, 'ses')));
        }
    }
}
