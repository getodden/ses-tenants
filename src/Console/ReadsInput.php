<?php

namespace Odden\SesTenants\Console;

/**
 * Console input as the strings it is for.
 */
trait ReadsInput
{
    protected function argumentString(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    protected function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }
}
