<?php

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Aws\Sts\StsClient;
use Odden\SesTenants\Exceptions\IdentityNotFound;
use Odden\SesTenants\Exceptions\SesTenantsException;
use Odden\SesTenants\Identities\SesV2Identities;

/**
 * An SES client answering from a queue, remembering the commands it was given.
 *
 * @param  list<Result|AwsException>  $queue
 * @return array{0: SesV2Client, 1: ArrayObject}
 */
function sesClient(array $queue): array
{
    $calls = new ArrayObject;
    $client = new SesV2Client(['version' => 'latest', 'region' => 'us-east-2', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => new MockHandler($queue)]);
    $client->getHandlerList()->appendInit(function (callable $next) use ($calls) {
        return function ($command) use ($next, $calls) {
            $calls[] = [$command->getName(), $command->toArray()];

            return $next($command);
        };
    });

    return [$client, $calls];
}

function sesError(string $code): AwsException
{
    return new AwsException($code, new Command('x'), ['code' => $code]);
}

function stsClient(): StsClient
{
    return new StsClient(['version' => 'latest', 'region' => 'us-east-2', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => new MockHandler([new Result(['Account' => '123456789012'])])]);
}

it('creates an identity with Easy DKIM and a custom MAIL FROM domain, and returns its state', function (): void {
    [$client, $calls] = sesClient([
        new Result([]), new Result([]),
        new Result(['DkimAttributes' => ['Tokens' => ['a', 'b', 'c'], 'Status' => 'PENDING'], 'MailFromAttributes' => ['MailFromDomainStatus' => 'PENDING'], 'VerifiedForSendingStatus' => false]),
    ]);

    $state = (new SesV2Identities($client, 'shared-set'))->create('mail.example.com', 'bounce.mail.example.com');

    expect($state->dkimTokens)->toBe(['a', 'b', 'c'])->and($state->isVerified())->toBeFalse()->and($state->problem())->toBe('Waiting for the DKIM records.')
        ->and($calls[0][0])->toBe('CreateEmailIdentity')
        ->and($calls[0][1])->toMatchArray(['EmailIdentity' => 'mail.example.com', 'ConfigurationSetName' => 'shared-set'])
        ->and($calls[1][1])->toMatchArray(['MailFromDomain' => 'bounce.mail.example.com', 'BehaviorOnMxFailure' => 'REJECT_MESSAGE']);
});

it('says so when the domain already has an identity, and removes a half-made one on any other failure', function (): void {
    [$client] = sesClient([sesError('AlreadyExistsException')]);

    expect(fn () => (new SesV2Identities($client))->create('mail.example.com', 'bounce.mail.example.com'))->toThrow(SesTenantsException::class, 'already set up');

    [$client, $calls] = sesClient([new Result([]), sesError('BadRequestException'), new Result([])]);

    expect(fn () => (new SesV2Identities($client))->create('mail.example.com', 'bounce.mail.example.com'))->toThrow(SesTenantsException::class, 'could not be reached');
    expect(array_column($calls->getArrayCopy(), 0))->toBe(['CreateEmailIdentity', 'PutEmailIdentityMailFromAttributes', 'DeleteEmailIdentity']);
});

it('treats an identity as verified only when DKIM, MAIL FROM and sending all pass', function (): void {
    $answer = fn (string $dkim, string $from, bool $sending) => new Result(['DkimAttributes' => ['Tokens' => ['a'], 'Status' => $dkim], 'MailFromAttributes' => ['MailFromDomainStatus' => $from], 'VerifiedForSendingStatus' => $sending]);
    [$client] = sesClient([$answer('SUCCESS', 'SUCCESS', true), $answer('SUCCESS', 'FAILED', true), $answer('SUCCESS', 'SUCCESS', false)]);
    $identities = new SesV2Identities($client);

    expect($identities->describe('a.example.com')->isVerified())->toBeTrue()
        ->and($identities->describe('a.example.com')->problem())->toBe('The bounce-domain records were not found or do not match.')
        ->and($identities->describe('a.example.com')->isVerified())->toBeFalse();
});

it('reports a missing identity apart from a failure', function (): void {
    [$client] = sesClient([sesError('NotFoundException'), sesError('BadRequestException')]);
    $identities = new SesV2Identities($client);

    expect(fn () => $identities->describe('a.example.com'))->toThrow(IdentityNotFound::class)
        ->and(fn () => $identities->describe('a.example.com'))->toThrow(SesTenantsException::class);
});

it('does not mind deleting an identity that is already gone', function (): void {
    [$client] = sesClient([sesError('NotFoundException')]);

    (new SesV2Identities($client))->delete('a.example.com');

    expect(true)->toBeTrue();
});

it('creates the SES tenant and attaches the identity and the configuration set to it', function (): void {
    [$client, $calls] = sesClient([new Result([]), new Result([]), new Result([])]);

    (new SesV2Identities($client, 'shared-set', stsClient()))->attachToTenant('customer-7', 'mail.example.com');

    expect(array_column($calls->getArrayCopy(), 0))->toBe(['CreateTenant', 'CreateTenantResourceAssociation', 'CreateTenantResourceAssociation'])
        ->and($calls[1][1])->toMatchArray(['TenantName' => 'customer-7', 'ResourceArn' => 'arn:aws:ses:us-east-2:123456789012:identity/mail.example.com'])
        ->and($calls[2][1]['ResourceArn'])->toBe('arn:aws:ses:us-east-2:123456789012:configuration-set/shared-set');
});

it('attaches again without complaint when the tenant and associations already exist', function (): void {
    [$client] = sesClient([sesError('AlreadyExistsException'), sesError('AlreadyExistsException'), sesError('AlreadyExistsException')]);

    (new SesV2Identities($client, 'shared-set', stsClient()))->attachToTenant('customer-7', 'mail.example.com');

    expect(true)->toBeTrue();
});

it('sets the reputation policy through the tenant\'s ARN and reads the tenant\'s sending status', function (): void {
    [$client, $calls] = sesClient([
        new Result(['Tenant' => ['TenantArn' => 'arn:aws:ses:us-east-2:123456789012:tenant/customer-7/abc']]), new Result([]),
        new Result(['Tenant' => ['TenantArn' => 'arn:aws:ses:us-east-2:123456789012:tenant/customer-7/abc']]),
        new Result(['ReputationEntity' => ['SendingStatusAggregate' => 'DISABLED', 'AwsSesManagedStatus' => ['Status' => 'DISABLED', 'Cause' => 'high bounce rate'], 'CustomerManagedStatus' => ['Status' => 'ENABLED']]]),
    ]);
    $identities = new SesV2Identities($client);

    $identities->setTenantPolicy('customer-7', 'strict');
    $status = $identities->tenantSendingStatus('customer-7');

    expect($calls[1][1])->toMatchArray(['ReputationEntityReference' => 'arn:aws:ses:us-east-2:123456789012:tenant/customer-7/abc', 'ReputationEntityPolicy' => 'arn:aws:ses:us-east-2:aws:reputation-policy/strict'])
        ->and($status->blocked())->toBeTrue()->and($status->awsEnforced())->toBeTrue()
        ->and($status->reason())->toContain('high bounce rate');
});
