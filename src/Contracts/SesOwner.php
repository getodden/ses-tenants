<?php

namespace Odden\SesTenants\Contracts;

/**
 * The Eloquent model that owns sending domains: a customer, a workspace, a team. Implement it on that model.
 */
interface SesOwner
{
    /**
     * The name of the owner's SES tenant: stable, unique per owner, and never shown to recipients (letters, digits, - and _).
     */
    public function sesTenantName(): string;

    /**
     * Whether the owner is one SES should watch more closely for now (on a trial, say). While it is, its SES tenant gets the
     * "risky" reputation policy; a domain still inside its warm-up counts as risky too, whatever this says.
     */
    public function sesIsRisky(): bool;
}
