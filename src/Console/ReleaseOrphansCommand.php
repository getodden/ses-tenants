<?php

namespace Odden\SesTenants\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Odden\SesTenants\Domains\SendingDomains;

/**
 * Retries removing the identities that could not be removed when an owner or a domain went away.
 */
class ReleaseOrphansCommand extends Command
{
    protected $signature = 'ses-tenants:release-orphans';

    protected $description = 'Retry removing identities left behind at Amazon SES';

    public function handle(SendingDomains $domains): int
    {
        $left = 0;
        $released = 0;

        foreach (DB::table('ses_orphaned_identities')->orderBy('id')->get() as $orphan) {
            if ($domains->releaseOrphan((string) $orphan->domain, $orphan->tenant_name === null ? null : (string) $orphan->tenant_name)) {
                DB::table('ses_orphaned_identities')->where('id', $orphan->id)->delete();
                $released++;
            } else {
                DB::table('ses_orphaned_identities')->where('id', $orphan->id)->update(['attempts' => $orphan->attempts + 1, 'last_error' => 'Amazon SES would not remove it.', 'updated_at' => now()]);
                $this->warn("{$orphan->domain}: still there (attempt ".($orphan->attempts + 1).').');
                $left++;
            }
        }

        $this->info("Released {$released} identit".($released === 1 ? 'y' : 'ies').", {$left} still waiting.");

        return self::SUCCESS;
    }
}
