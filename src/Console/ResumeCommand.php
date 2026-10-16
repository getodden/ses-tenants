<?php

namespace Odden\SesTenants\Console;

use Illuminate\Console\Command;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Reputation\Reputation;

class ResumeCommand extends Command
{
    use FindsOwners;

    protected $signature = 'ses-tenants:resume {owner : The owner\'s key}';

    protected $description = "Lift an owner's pause (after it has cleaned its list)";

    public function handle(Reputation $reputation): int
    {
        $owner = $this->owner($this->argumentString('owner'));

        if ($owner === null) {
            return self::FAILURE;
        }

        try {
            $this->info($reputation->resume($owner) ? 'Resumed. Its rates count from today.' : 'It was not paused.');
        } catch (SesTenantsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
