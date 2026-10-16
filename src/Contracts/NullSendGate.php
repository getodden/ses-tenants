<?php

namespace Odden\SesTenants\Contracts;

/**
 * Allows everything and records nothing: the gate until the application supplies its own.
 */
final class NullSendGate implements SendGate
{
    public function before(int|string $ownerId, int $recipients): void {}

    public function after(int|string $ownerId, int $recipients, ?string $domain = null): void {}
}
