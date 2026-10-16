<?php

namespace Odden\SesTenants\Identities;

use Odden\SesTenants\Exceptions\SesTenantsException;

/**
 * Sending is off: nothing can be created, and there is nothing to delete.
 */
final class NullIdentities implements SesIdentities
{
    public function enabled(): bool
    {
        return false;
    }

    public function create(string $domain, string $mailFromDomain): IdentityState
    {
        throw new SesTenantsException('Sending through Amazon SES is not configured.');
    }

    public function describe(string $domain): IdentityState
    {
        throw new SesTenantsException('Sending through Amazon SES is not configured.');
    }

    public function delete(string $domain): void {}

    public function attachToTenant(string $tenantName, string $domain): void
    {
        throw new SesTenantsException('Sending through Amazon SES is not configured.');
    }

    public function detachFromTenant(string $tenantName, string $domain): void {}

    public function setTenantPolicy(string $tenantName, string $policy): void {}

    public function tenantSendingStatus(string $tenantName): ?TenantSendingStatus
    {
        return null;
    }

    public function setTenantSending(string $tenantName, bool $enabled): void {}

    public function deleteTenant(string $tenantName): void {}
}
