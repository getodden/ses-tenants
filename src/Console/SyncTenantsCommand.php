<?php

namespace Odden\SesTenants\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Odden\SesTenants\Contracts\SesOwner;
use Odden\SesTenants\Domains\SendingDomains;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Reputation;

/**
 * Brings SES in line with the database: each owner's SES tenant is attached to its domains and the configuration set, its
 * reputation policy is current, and its pause matches what SES says. Run hourly (and once after changing the configuration set).
 */
class SyncTenantsCommand extends Command
{
    protected $signature = 'ses-tenants:sync';

    protected $description = "Attach each owner's SES tenant to its domains, refresh its policy and pause state";

    public function handle(SesIdentities $identities, SendingDomains $domains, Reputation $reputation): int
    {
        if (! $identities->enabled()) {
            $this->warn('Sending through Amazon SES is not switched on.');

            return self::SUCCESS;
        }

        $failed = 0;
        $all = SendingDomain::query()->with('owner')->orderBy('owner_id')->orderBy('domain')->get();

        foreach ($all as $domain) {
            $owner = $domain->owner;

            if (! $owner instanceof SesOwner) {
                continue;
            }

            try {
                $identities->attachToTenant($owner->sesTenantName(), $domain->domain);
            } catch (SesTenantsException $e) {
                $failed++;
                $this->error("{$domain->domain}: {$e->getMessage()}");
            }
        }

        $seen = [];

        foreach ($all as $domain) {
            $owner = $domain->owner;

            if (! $owner instanceof Model || ! $owner instanceof SesOwner || isset($seen[$owner->getMorphClass().':'.$owner->getKey()])) {
                continue;
            }

            $seen[$owner->getMorphClass().':'.$owner->getKey()] = true;
            $domains->syncPolicy($owner);
            $reputation->syncWithSes($owner);
        }

        $this->info('Synced '.($all->count() - $failed)." of {$all->count()} sending domain(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
