<?php

namespace Odden\SesTenants\Tests\Support;

use Odden\SesTenants\Exceptions\IdentityNotFound;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Identities\IdentityState;
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Identities\TenantSendingStatus;

/**
 * SES, remembering what it was asked and answering from tables the test controls.
 */
class FakeIdentities implements SesIdentities
{
    /** @var array<string, IdentityState> */
    public array $identities = [];

    /** @var list<string> */
    public array $deleted = [];

    public bool $failCreate = false;

    public bool $failDelete = false;

    public bool $unreachable = false;

    public bool $failAttach = false;

    public bool $failPolicy = false;

    /** @var array<string, list<string>> SES tenant name => the domains attached to it */
    public array $tenants = [];

    /** @var list<array{string, string}> */
    public array $detached = [];

    /** @var array<string, string> */
    public array $policies = [];

    /** @var array<string, TenantSendingStatus> */
    public array $tenantStatuses = [];

    /** @var list<array{string, bool}> */
    public array $sendingChanges = [];

    public function enabled(): bool
    {
        return true;
    }

    public function create(string $domain, string $mailFromDomain): IdentityState
    {
        if ($this->failCreate) {
            throw new SesTenantsException('Amazon SES could not be reached or refused the request.');
        }

        return $this->identities[$domain] = new IdentityState(['tok1', 'tok2', 'tok3'], 'PENDING', 'PENDING', false);
    }

    public function describe(string $domain): IdentityState
    {
        if ($this->unreachable) {
            throw new SesTenantsException('Amazon SES could not be reached or refused the request.');
        }

        return $this->identities[$domain] ?? throw new IdentityNotFound('The sending identity no longer exists.');
    }

    public function delete(string $domain): void
    {
        if ($this->failDelete) {
            throw new SesTenantsException('refused');
        }

        // As at SES: an identity a tenant still holds cannot be deleted.
        foreach ($this->tenants as $domains) {
            if (in_array($domain, $domains, true)) {
                throw new SesTenantsException('Cannot delete the identity because it has tenant associations.');
            }
        }

        unset($this->identities[$domain]);
        $this->deleted[] = $domain;
    }

    public function attachToTenant(string $tenantName, string $domain): void
    {
        if ($this->failAttach) {
            throw new SesTenantsException('Amazon SES could not be reached or refused the request.');
        }

        $this->tenants[$tenantName][] = $domain;
    }

    public function detachFromTenant(string $tenantName, string $domain): void
    {
        $this->detached[] = [$tenantName, $domain];

        if (isset($this->tenants[$tenantName])) {
            $this->tenants[$tenantName] = array_values(array_diff($this->tenants[$tenantName], [$domain]));
        }
    }

    public function setTenantPolicy(string $tenantName, string $policy): void
    {
        if ($this->failPolicy) {
            throw new SesTenantsException('Amazon SES could not be reached or refused the request.');
        }

        $this->policies[$tenantName] = $policy;
    }

    public function tenantSendingStatus(string $tenantName): ?TenantSendingStatus
    {
        return $this->tenantStatuses[$tenantName] ?? new TenantSendingStatus('ENABLED', 'ENABLED', null, 'ENABLED', null, null);
    }

    public function setTenantSending(string $tenantName, bool $enabled): void
    {
        $this->sendingChanges[] = [$tenantName, $enabled];
        $current = $this->tenantStatuses[$tenantName] ?? new TenantSendingStatus('ENABLED', 'ENABLED', null, 'ENABLED', null, null);
        $customer = $enabled ? 'ENABLED' : 'DISABLED';
        $aggregate = ($current->awsStatus === 'DISABLED' || $customer === 'DISABLED') ? 'DISABLED' : 'ENABLED';
        $this->tenantStatuses[$tenantName] = new TenantSendingStatus($aggregate, $current->awsStatus, $current->awsCause, $customer, null, $current->impact);
    }

    public function deleteTenant(string $tenantName): void
    {
        unset($this->tenants[$tenantName]);
    }

    /** The domain's DNS records pass at SES. */
    public function passes(string $domain): void
    {
        $this->identities[$domain] = new IdentityState(['tok1', 'tok2', 'tok3'], 'SUCCESS', 'SUCCESS', true);
    }
}
