<?php

namespace Odden\SesTenants\Domains;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Odden\SesTenants\Contracts\AdoptsDomains;
use Odden\SesTenants\Contracts\SesOwner;
use Odden\SesTenants\Dns\DnsRecords;
use Odden\SesTenants\Events\DomainChanged;
use Odden\SesTenants\Exceptions\IdentityNotFound;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Identities\IdentityState;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Identities\TenantSendingStatus;
use Odden\SesTenants\Models\OwnerStatus;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Warmup;

/**
 * An owner's sending domains, and how one moves between its states:
 *
 *   pending   added; SES has not seen all the DNS records yet (re-checked every run)
 *   verified  SES confirmed DKIM and the bounce domain; the owner may send from it (re-checked daily)
 *   failed    the records never appeared within the wait, or a verified domain lost them; it does not send
 *             (re-checked hourly, so fixing DNS recovers it)
 *
 * The identity at SES is named by the domain and shared account-wide, so a domain belongs to one owner at a time, and an
 * owner can't choose a name that would touch another's. Authorisation (who may add or remove a domain) and plan checks
 * are the application's: this does not know who is asking.
 */
class SendingDomains
{
    private const int ADDS_PER_HOUR = 10;

    private const string IDENTITY_MISSING = 'The sending identity no longer exists at Amazon SES. Remove this domain and add it again.';

    public function __construct(
        private readonly SesIdentities $identities,
        private readonly DomainPolicy $policy,
        private readonly Warmup $warmup,
    ) {}

    public function enabled(): bool
    {
        return $this->identities->enabled();
    }

    /**
     * The domains each owner (by key, or by sesAdoptKey() when the owner implements AdoptsDomains) may take over as they are, though they are reserved or already have an identity at SES:
     * the application's own names, for its own account. They are never deleted at SES.
     *
     * @return array<string, list<string>>
     */
    public function adoptable(): array
    {
        $adopt = [];

        foreach ((array) config('ses-tenants.adopt', []) as $key => $domains) {
            foreach ((array) $domains as $domain) {
                $adopt[(string) $key][] = strtolower((string) $domain);
            }
        }

        return $adopt;
    }

    public function isAdopted(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        foreach ($this->adoptable() as $list) {
            if (in_array($domain, $list, true)) {
                return true;
            }
        }

        return false;
    }

    public function adoptAllowed(Model $owner, string $input): bool
    {
        $domain = DomainPolicy::canonical($input);

        $key = $owner instanceof AdoptsDomains ? $owner->sesAdoptKey() : (string) $owner->getKey();

        return $domain !== '' && in_array($domain, $this->adoptable()[$key] ?? [], true);
    }

    /**
     * Why a domain can't be used for sending, or null if it can.
     */
    public function problem(string $input, ?Model $owner = null): ?string
    {
        if ($owner !== null && $this->adoptAllowed($owner, $input)) {
            return null;
        }

        return $this->policy->problem($input);
    }

    /**
     * @throws SesTenantsException
     */
    public function add(Model&SesOwner $owner, string $input): SendingDomain
    {
        $problem = $this->problem($input, $owner);

        if ($problem !== null) {
            throw new SesTenantsException($problem);
        }

        $domain = DomainPolicy::canonical($input);
        $max = (int) config('ses-tenants.domains.max_per_owner', 3);

        if (SendingDomain::query()->ownedBy($owner)->count() >= $max) {
            throw new SesTenantsException((string) trans('ses-tenants::messages.domain_limit', ['max' => $max]));
        }

        $limiter = 'ses-tenants:domain-adds:'.$owner->getMorphClass().':'.$owner->getKey();

        if (RateLimiter::tooManyAttempts($limiter, self::ADDS_PER_HOUR)) {
            throw new SesTenantsException('Too many domains added just now. Try again in an hour.');
        }

        RateLimiter::hit($limiter, 3600);

        // The row goes first: it holds the name for this owner (the database refuses a second claim), so SES is only asked
        // for a domain nobody else holds.
        try {
            // Nested transaction: a refused name rolls back to a savepoint instead of aborting the caller's transaction.
            $record = DB::transaction(function () use ($owner, $domain): SendingDomain {
                OwnerStatus::query()->ownedBy($owner)->firstOr(fn () => OwnerStatus::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]));

                return SendingDomain::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(), 'domain' => $domain, 'status' => SendingDomain::PENDING]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new SesTenantsException('That domain is already in use.');
        }

        $adopted = false;

        try {
            // One of the application's own domains may already have an identity at SES (made by hand, and in use): it is taken
            // over as it is, with no change to it and none to DNS.
            if ($this->adoptAllowed($owner, $domain)) {
                try {
                    $state = $this->identities->describe($domain);
                    $adopted = true;
                } catch (IdentityNotFound) {
                    $state = $this->identities->create($domain, $record->mailFromDomain());
                }
            } else {
                $state = $this->identities->create($domain, $record->mailFromDomain());
            }

            $this->identities->attachToTenant($owner->sesTenantName(), $domain);
        } catch (SesTenantsException $e) {
            // Nothing is kept half made: the identity goes too, so a retry starts clean.
            $this->release($record);
            $record->delete();

            throw $e;
        }

        $record->forceFill(['records' => $this->records($record, $state), 'last_checked_at' => now()])->save();
        $this->syncPolicy($owner, true);
        event(new DomainChanged($record, $adopted ? 'adopted' : 'added', ['domain' => $domain]));

        return $record;
    }

    /**
     * Ask SES where the domain stands and move it to the state it earns. Returns true when a verified domain has just stopped
     * being verified (so someone can be told).
     */
    public function check(SendingDomain $record): bool
    {
        try {
            $state = $this->identities->describe($record->domain);
        } catch (IdentityNotFound) {
            // Deleted at SES behind our back: nothing can be sent from it any more, so it stops working here too.
            $wasVerified = $record->isVerified();
            $wasFailed = $record->status === SendingDomain::FAILED;
            $record->forceFill(['status' => SendingDomain::FAILED, 'last_error' => self::IDENTITY_MISSING, 'last_checked_at' => now()])->save();

            $wasFailed || event(new DomainChanged($record, 'failed', ['reason' => 'identity_missing']));

            return $wasVerified;
        } catch (SesTenantsException $e) {
            // Not reachable right now: say so, but don't change what the domain is allowed to do.
            $record->forceFill(['last_checked_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return false;
        }

        $wasVerified = $record->isVerified();

        if ($state->isVerified()) {
            $record->forceFill(['status' => SendingDomain::VERIFIED, 'verified_at' => $record->verified_at ?? now(), 'last_error' => null, 'last_checked_at' => now()])->save();

            $wasVerified || event(new DomainChanged($record, 'verified'));

            return false;
        }

        $expired = $record->created_at !== null && $record->created_at->lt(now()->subHours((int) config('ses-tenants.domains.pending_hours', 72)));
        $failing = $wasVerified || $expired || $record->status === SendingDomain::FAILED;

        $record->forceFill([
            'status' => $failing ? SendingDomain::FAILED : SendingDomain::PENDING,
            'last_error' => $state->problem(),
            'last_checked_at' => now(),
        ])->save();

        $wasVerified && event(new DomainChanged($record, 'failed', ['reason' => $state->problem(), 'was_verified' => true]));

        return $wasVerified;
    }

    /**
     * Take the domain off SES first, so it never stays registered after the owner let go of it. Returns false (and keeps the
     * row, so it can be retried) when SES would not release it.
     */
    public function remove(SendingDomain $record): bool
    {
        if (! $this->release($record)) {
            return false;
        }

        $record->delete();
        event(new DomainChanged($record, 'removed', ['domain' => $record->domain]));
        $this->releaseTenantIfUnused($record);

        return true;
    }

    /**
     * Drop a claim that was never verified and has outlived its wait, so a name that was added and abandoned (or squatted) is
     * free for its real owner. A domain that was ever verified is never dropped here. Returns true when it was dropped (false
     * when it is not due, or SES would not release it; it is retried).
     */
    public function dropUnverified(SendingDomain $record): bool
    {
        $due = $record->verified_at === null
            && $record->created_at !== null
            && $record->created_at->lt(now()->subDays((int) config('ses-tenants.domains.drop_unverified_days', 7)));

        if (! $due || ! $this->release($record)) {
            return false;
        }

        $record->delete();
        event(new DomainChanged($record, 'dropped', ['domain' => $record->domain]));
        $this->releaseTenantIfUnused($record);

        return true;
    }

    /**
     * Delete the identity at SES (one already gone counts as released).
     */
    public function release(SendingDomain $record): bool
    {
        $name = $this->tenantNameOf($record);

        // SES will not delete an identity its tenant still holds. If detaching fails the delete is still tried: it works when
        // the tenant is already gone.
        if ($name !== null) {
            try {
                $this->identities->detachFromTenant($name, $record->domain);
            } catch (SesTenantsException) {
                // Reported by the SES wrapper.
            }
        }

        return $this->deleteWithRetry($record->domain);
    }

    /**
     * Let go of everything an owner holds, for when the owner is deleted: its identities go at SES and then its SES tenant, and
     * its standing and usage rows are deleted. One SES will not release yet is recorded, so `ses-tenants:release-orphans` retries it.
     */
    public function forgetOwner(Model&SesOwner $owner): void
    {
        foreach (SendingDomain::query()->ownedBy($owner)->get() as $record) {
            if (! $this->release($record)) {
                $this->recordOrphan($record->domain, $owner->sesTenantName());
            }

            $record->delete();
        }

        $this->forgetTenant($owner->sesTenantName());
        $this->purgeOwnerRecords($owner);
    }

    /**
     * Delete what the package keeps for an owner: its domains, standing and usage. Nothing is done at SES; release the identities
     * first (see {@see self::release()}), or use {@see self::forgetOwner()}.
     */
    public function purgeOwnerRecords(Model $owner): void
    {
        SendingDomain::query()->ownedBy($owner)->delete();
        OwnerStatus::query()->ownedBy($owner)->delete();
        DB::table('ses_usage')->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())->delete();
    }

    /**
     * Remember an identity that could not be removed.
     */
    public function recordOrphan(string $domain, ?string $tenantName): void
    {
        DB::table('ses_orphaned_identities')->updateOrInsert(['domain' => $domain], ['tenant_name' => $tenantName, 'updated_at' => now(), 'created_at' => now()]);
    }

    /**
     * Remove an identity left behind (its row may be gone): detach it from its SES tenant if that still exists, then delete it.
     * True when it is gone at SES.
     */
    public function releaseOrphan(string $domain, ?string $tenantName): bool
    {
        if ($tenantName !== null) {
            try {
                $this->identities->detachFromTenant($tenantName, $domain);
            } catch (SesTenantsException) {
                // Reported by the SES wrapper.
            }
        }

        return $this->deleteWithRetry($domain);
    }

    /**
     * The reputation policy the owner's SES tenant should have now: the stricter one while the owner is risky or one of its
     * domains (verified or not yet) is still inside its warm-up.
     */
    public function policyFor(Model&SesOwner $owner): string
    {
        $risky = $owner->sesIsRisky() || SendingDomain::query()->ownedBy($owner)->get()->contains(fn (SendingDomain $domain): bool => $this->warmup->step($domain) !== null);

        return (string) config('ses-tenants.ses_policy.'.($risky ? 'risky' : 'normal'), $risky ? 'strict' : 'standard');
    }

    /**
     * Sets the SES tenant's reputation policy when it differs from what was last set (or when forced). A failure is left for the
     * next check.
     */
    public function syncPolicy(Model&SesOwner $owner, bool $force = false): void
    {
        if (! $this->identities->enabled()) {
            return;
        }

        $policy = $this->policyFor($owner);
        $status = $this->status($owner);

        if (! $force && $status->ses_policy === $policy) {
            return;
        }

        try {
            $this->identities->setTenantPolicy($owner->sesTenantName(), $policy);
            $status->forceFill(['ses_policy' => $policy])->save();
        } catch (SesTenantsException) {
            // Reported by the SES wrapper; tried again at the next check.
        }
    }

    /**
     * Pause or resume the owner's SES tenant (the application's side of it). Throws when SES cannot be reached, so a resume is
     * not recorded here while SES still holds the owner paused. Returns where the tenant stands afterwards.
     *
     * @throws SesTenantsException
     */
    public function setSending(Model&SesOwner $owner, bool $enabled): ?TenantSendingStatus
    {
        if (! $this->identities->enabled()) {
            return null;
        }

        $this->identities->setTenantSending($owner->sesTenantName(), $enabled);

        return $this->identities->tenantSendingStatus($owner->sesTenantName());
    }

    /**
     * Where the owner's SES tenant stands (null when sending is off, the tenant does not exist, or SES cannot be reached).
     */
    public function sesStatus(Model&SesOwner $owner): ?TenantSendingStatus
    {
        if (! $this->identities->enabled()) {
            return null;
        }

        try {
            return $this->identities->tenantSendingStatus($owner->sesTenantName());
        } catch (SesTenantsException) {
            return null;
        }
    }

    public function status(Model $owner): OwnerStatus
    {
        return OwnerStatus::query()->ownedBy($owner)->firstOr(fn () => OwnerStatus::query()->create(['owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()]));
    }

    /**
     * The owner's SES tenant has no use once its last domain is gone. Failing to delete it is harmless (an empty tenant sends
     * nothing, and the next domain re-uses it).
     */
    public function releaseTenantIfUnused(SendingDomain $record): void
    {
        if (SendingDomain::query()->where('owner_type', $record->owner_type)->where('owner_id', $record->owner_id)->exists()) {
            return;
        }

        $name = $this->tenantNameOf($record);
        $name === null || $this->forgetTenant($name);
    }

    public function forgetTenant(string $tenantName): void
    {
        try {
            $this->identities->deleteTenant($tenantName);
        } catch (SesTenantsException) {
            // Reported by the SES wrapper.
        }
    }

    private function tenantNameOf(SendingDomain $record): ?string
    {
        $owner = $record->owner;

        return $owner instanceof SesOwner ? $owner->sesTenantName() : null;
    }

    /**
     * SES takes a little while to let go of an identity after its tenant association is removed, so a refusal right after a
     * detach is tried again a few times before it counts as failed (the orphan retry covers the rest).
     */
    private function deleteWithRetry(string $domain): bool
    {
        // One of the application's own domains is let go of (the owner's hold is already removed) but never deleted: it may be
        // in use by something outside the app.
        if ($this->isAdopted($domain)) {
            return true;
        }

        $attempts = max(1, (int) config('ses-tenants.domains.release_attempts', 4));

        for ($try = 1; $try <= $attempts; $try++) {
            try {
                $this->identities->delete($domain);

                return true;
            } catch (SesTenantsException) {
                $try < $attempts && usleep(max(0, (int) config('ses-tenants.domains.release_wait_ms', 3000)) * 1000);
            }
        }

        return false;
    }

    /**
     * @return list<array{type: string, name: string, value: string, purpose: string, required: bool}>
     */
    private function records(SendingDomain $record, IdentityState $state): array
    {
        return DnsRecords::for($record->domain, $state->dkimTokens, $record->mailFromDomain(), (string) config('ses-tenants.region'));
    }
}
