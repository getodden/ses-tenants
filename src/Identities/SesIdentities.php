<?php

namespace Odden\SesTenants\Identities;

use Odden\SesTenants\Exceptions\IdentityNotFound;
use Odden\SesTenants\Exceptions\SesTenantsException;

/**
 * Amazon SES sending identities: one per verified sending domain, named by the
 * domain, shared by the whole account, so a domain belongs to one customer at a time.
 */
interface SesIdentities
{
    /**
     * Whether sending is switched on and configured.
     */
    public function enabled(): bool;

    /**
     * Create the identity with Easy DKIM and a custom MAIL FROM domain, and return its state (with the DKIM tokens).
     *
     * @throws SesTenantsException
     */
    public function create(string $domain, string $mailFromDomain): IdentityState;

    /**
     * @throws IdentityNotFound when the provider has no identity for the domain
     * @throws SesTenantsException
     */
    public function describe(string $domain): IdentityState;

    /**
     * Delete the identity. One that is already gone is fine.
     *
     * @throws SesTenantsException
     */
    public function delete(string $domain): void;

    /**
     * Make sure the customer's SES tenant exists and holds the domain's identity, so its sending and its
     * reputation are attributed (and can be paused) apart from every other customer's.
     *
     * @throws SesTenantsException
     */
    public function attachToTenant(string $tenantName, string $domain): void;

    /**
     * Take the domain's identity out of the customer's SES tenant. SES refuses to delete an identity that a tenant
     * still holds, so this comes before delete(). One that is not attached (or a tenant that is gone) is fine.
     *
     * @throws SesTenantsException
     */
    public function detachFromTenant(string $tenantName, string $domain): void;

    /**
     * Set the customer's SES tenant reputation policy: "standard" (pause on high-severity findings), "strict" (pause on any
     * finding) or "none". A tenant that does not exist is left alone.
     *
     * @throws SesTenantsException
     */
    public function setTenantPolicy(string $tenantName, string $policy): void;

    /**
     * Where the customer's SES tenant stands (null when it does not exist).
     *
     * @throws SesTenantsException
     */
    public function tenantSendingStatus(string $tenantName): ?TenantSendingStatus;

    /**
     * Pause or resume the tenant on our side. A pause SES enforced itself is not lifted by this.
     *
     * @throws SesTenantsException
     */
    public function setTenantSending(string $tenantName, bool $enabled): void;

    /**
     * Delete the SES tenant once it holds no identities. One that is already gone is fine.
     *
     * @throws SesTenantsException
     */
    public function deleteTenant(string $tenantName): void;
}
