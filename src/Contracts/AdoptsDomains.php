<?php

namespace Odden\SesTenants\Contracts;

/**
 * Optional, on the owner model: the key an owner is listed under in ses-tenants.adopt, when that is not its primary key (a
 * slug, say, so the list can be written before the owner exists).
 */
interface AdoptsDomains
{
    public function sesAdoptKey(): string;
}
