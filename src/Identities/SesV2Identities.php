<?php

namespace Odden\SesTenants\Identities;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Odden\SesTenants\Exceptions\IdentityNotFound;
use Odden\SesTenants\Exceptions\SesTenantsException;

/**
 * Amazon SES (the v2 API): an identity per sending domain, with Easy DKIM and a custom MAIL FROM domain.
 */
final class SesV2Identities implements SesIdentities
{
    private ?string $accountId = null;

    public function __construct(private readonly SesV2Client $client, private readonly ?string $configurationSet = null, private readonly ?StsClient $sts = null) {}

    public function enabled(): bool
    {
        return true;
    }

    public function create(string $domain, string $mailFromDomain): IdentityState
    {
        try {
            $this->client->createEmailIdentity(array_filter([
                'EmailIdentity' => $domain,
                'DkimSigningAttributes' => ['NextSigningKeyLength' => 'RSA_2048_BIT'],
                'ConfigurationSetName' => $this->configurationSet,
            ]));

            $this->client->putEmailIdentityMailFromAttributes([
                'EmailIdentity' => $domain,
                'MailFromDomain' => $mailFromDomain,
                'BehaviorOnMxFailure' => 'REJECT_MESSAGE',
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AlreadyExistsException') {
                throw new SesTenantsException('That domain is already set up for sending.');
            }

            // Whatever was half created is removed, so a retry starts clean.
            try {
                $this->delete($domain);
            } catch (SesTenantsException) {
                // Already reported; the original failure is what the person needs to hear about.
            }

            throw $this->failure($e);
        }

        return $this->describe($domain);
    }

    public function describe(string $domain): IdentityState
    {
        try {
            $identity = $this->client->getEmailIdentity(['EmailIdentity' => $domain]);
        } catch (AwsException $e) {
            // The provider answering "no such identity" is not a failure to report: it is what the domain's state is.
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                throw new IdentityNotFound('The sending identity no longer exists at Amazon SES.');
            }

            throw $this->failure($e);
        }

        $tokens = $identity['DkimAttributes']['Tokens'] ?? [];

        return new IdentityState(
            dkimTokens: array_values(array_map('strval', is_array($tokens) ? $tokens : [])),
            dkimStatus: (string) ($identity['DkimAttributes']['Status'] ?? 'NOT_STARTED'),
            mailFromStatus: (string) ($identity['MailFromAttributes']['MailFromDomainStatus'] ?? 'PENDING'),
            verifiedForSending: (bool) ($identity['VerifiedForSendingStatus'] ?? false),
        );
    }

    public function delete(string $domain): void
    {
        try {
            $this->client->deleteEmailIdentity(['EmailIdentity' => $domain]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return;
            }

            throw $this->failure($e);
        }
    }

    public function attachToTenant(string $tenantName, string $domain): void
    {
        try {
            try {
                $this->client->createTenant(['TenantName' => $tenantName]);
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                    throw $e;
                }
            }

            // A send on behalf of a tenant may only use resources the tenant holds: the domain's identity, and the
            // configuration set every message goes through (one set is shared by all tenants).
            $arns = [$this->arn('identity/'.$domain)];

            if ($this->configurationSet !== null && $this->configurationSet !== '') {
                $arns[] = $this->arn('configuration-set/'.$this->configurationSet);
            }

            foreach ($arns as $arn) {
                try {
                    $this->client->createTenantResourceAssociation(['TenantName' => $tenantName, 'ResourceArn' => $arn]);
                } catch (AwsException $e) {
                    if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                        throw $e;
                    }
                }
            }
        } catch (AwsException $e) {
            throw $this->failure($e);
        }
    }

    public function detachFromTenant(string $tenantName, string $domain): void
    {
        try {
            $this->client->deleteTenantResourceAssociation(['TenantName' => $tenantName, 'ResourceArn' => $this->arn('identity/'.$domain)]);
        } catch (AwsException $e) {
            // Not attached, or the tenant is already gone: nothing to detach.
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return;
            }

            throw $this->failure($e);
        }
    }

    public function setTenantPolicy(string $tenantName, string $policy): void
    {
        try {
            $this->client->updateReputationEntityPolicy([
                'ReputationEntityType' => 'RESOURCE',
                'ReputationEntityReference' => $this->tenantArn($tenantName),
                'ReputationEntityPolicy' => sprintf('arn:aws:ses:%s:aws:reputation-policy/%s', $this->client->getRegion(), $policy),
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return;
            }

            throw $this->failure($e);
        }
    }

    public function tenantSendingStatus(string $tenantName): ?TenantSendingStatus
    {
        try {
            $entity = $this->client->getReputationEntity(['ReputationEntityType' => 'RESOURCE', 'ReputationEntityReference' => $this->tenantArn($tenantName)])['ReputationEntity'] ?? [];
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return null;
            }

            throw $this->failure($e);
        }

        return new TenantSendingStatus(
            (string) ($entity['SendingStatusAggregate'] ?? 'ENABLED'),
            (string) ($entity['AwsSesManagedStatus']['Status'] ?? 'ENABLED'),
            isset($entity['AwsSesManagedStatus']['Cause']) ? (string) $entity['AwsSesManagedStatus']['Cause'] : null,
            (string) ($entity['CustomerManagedStatus']['Status'] ?? 'ENABLED'),
            isset($entity['CustomerManagedStatus']['Cause']) ? (string) $entity['CustomerManagedStatus']['Cause'] : null,
            isset($entity['ReputationImpact']) ? (string) $entity['ReputationImpact'] : null,
        );
    }

    public function setTenantSending(string $tenantName, bool $enabled): void
    {
        try {
            $this->client->updateReputationEntityCustomerManagedStatus([
                'ReputationEntityType' => 'RESOURCE',
                'ReputationEntityReference' => $this->tenantArn($tenantName),
                'SendingStatus' => $enabled ? 'ENABLED' : 'DISABLED',
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return;
            }

            throw $this->failure($e);
        }
    }

    /**
     * The tenant's ARN, which is how the reputation operations name it (the tenant is looked up by its name).
     */
    private function tenantArn(string $tenantName): string
    {
        return (string) $this->client->getTenant(['TenantName' => $tenantName])['Tenant']['TenantArn'];
    }

    public function deleteTenant(string $tenantName): void
    {
        try {
            $this->client->deleteTenant(['TenantName' => $tenantName]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                return;
            }

            throw $this->failure($e);
        }
    }

    /**
     * An SES resource's ARN, built from the region and the account the credentials belong to (looked up once).
     */
    private function arn(string $resource): string
    {
        $this->accountId ??= (string) $this->sts?->getCallerIdentity()['Account'];

        if ($this->accountId === '') {
            throw new SesTenantsException('The AWS account could not be identified.');
        }

        return 'arn:aws:ses:'.$this->client->getRegion().':'.$this->accountId.':'.$resource;
    }

    /**
     * The provider's own text is logged, never shown: it can name accounts and limits.
     */
    private function failure(AwsException $e): SesTenantsException
    {
        report($e);

        return new SesTenantsException('Amazon SES could not be reached or refused the request. Try again in a few minutes.');
    }
}
