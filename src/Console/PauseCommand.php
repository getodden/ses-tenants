<?php

namespace Odden\SesTenants\Console;

use Illuminate\Console\Command;
use Odden\SesTenants\Reputation\Reputation;

class PauseCommand extends Command
{
    use FindsOwners;

    protected $signature = 'ses-tenants:pause {owner : The owner\'s key} {--reason=Paused by an operator. : Why}';

    protected $description = "Pause an owner's sending at once";

    public function handle(Reputation $reputation): int
    {
        $owner = $this->owner($this->argumentString('owner'));

        if ($owner === null) {
            return self::FAILURE;
        }

        $reputation->pause($owner, $this->optionString('reason') ?? 'Paused by an operator.', [], 'manual');
        $this->info('Paused.');

        return self::SUCCESS;
    }
}
