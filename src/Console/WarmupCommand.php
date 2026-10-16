<?php

namespace Odden\SesTenants\Console;

use Illuminate\Console\Command;
use Odden\SesTenants\Models\SendingDomain;
use Odden\SesTenants\Reputation\Warmup;

/**
 * Control over a sending domain's warm-up: see where it stands, skip it for a domain that already sends elsewhere with a good
 * record, start it further along, or start it again.
 */
class WarmupCommand extends Command
{
    use ReadsInput;

    protected $signature = 'ses-tenants:warmup {domain : The sending domain} {--skip : Skip the warm-up} {--step= : Start the warm-up at this step (0 is the first)} {--reset : Start the warm-up again from the first step}';

    protected $description = "Show or change a sending domain's warm-up";

    public function handle(Warmup $warmup): int
    {
        $domain = SendingDomain::query()->where('domain', strtolower($this->argumentString('domain')))->first();

        if ($domain === null) {
            $this->error('No such sending domain.');

            return self::FAILURE;
        }

        $changes = array_filter([$this->option('skip') ? 'skip' : null, $this->option('step') !== null ? 'step' : null, $this->option('reset') ? 'reset' : null]);

        if (count($changes) > 1) {
            $this->error('Use one of --skip, --step and --reset.');

            return self::FAILURE;
        }

        if ($changes !== []) {
            $change = array_values($changes)[0];

            if ($change === 'step' && (! ctype_digit($this->optionString('step') ?? '') || (int) $this->optionString('step') >= count($warmup->steps()))) {
                $this->error('The step is a number from 0 to '.(count($warmup->steps()) - 1).'.');

                return self::FAILURE;
            }

            $domain->forceFill(match ($change) {
                'skip' => ['warmup_skipped_at' => now()],
                'step' => ['warmup_skipped_at' => null, 'warmup_start_step' => (int) $this->optionString('step')],
                default => ['warmup_skipped_at' => null, 'warmup_start_step' => 0, 'first_sent_on' => null],
            })->save();
        }

        $step = $warmup->step($domain);
        $this->info($domain->domain.': '.($domain->warmup_skipped_at !== null ? 'warm-up skipped' : ($step === null ? 'warm-up finished (or not started and switched off)' : sprintf('step %d of %d, today\'s limit %s, sent today %s', $step + 1, count($warmup->steps()), number_format((int) $warmup->ceiling($domain)), number_format($warmup->sentToday($domain))))));

        return self::SUCCESS;
    }
}
