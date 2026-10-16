<?php

namespace Odden\SesTenants\Console;

use Illuminate\Database\Eloquent\Model;
use Odden\SesTenants\Contracts\SesOwner;

/**
 * Looks an owner up by key in the configured owner model (ses-tenants.persistence.owner_model).
 */
trait FindsOwners
{
    use ReadsInput;

    protected function owner(string $key): (Model&SesOwner)|null
    {
        $class = (string) config('ses-tenants.persistence.owner_model');

        if (! is_a($class, Model::class, true) || ! is_a($class, SesOwner::class, true)) {
            $this->error('Set ses-tenants.persistence.owner_model to a model that implements '.SesOwner::class.'.');

            return null;
        }

        $owner = $class::query()->find($key);

        if ($owner === null) {
            $this->error("No owner with key {$key}.");

            return null;
        }

        return $owner;
    }
}
