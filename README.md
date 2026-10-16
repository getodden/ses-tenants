# Odden SES Tenants

Per-customer sending domains on Amazon SES for Laravel SaaS.

One SES account, many customers, each sending from its own verified domain with its reputation kept apart from everyone else's.

Status: **0.2, in development.** 0.1 is identities, SES tenants, the guarded transport, verified SNS events and DNS records. 0.2 adds an opt-in layer that keeps sending domains, usage, warm-up and reputation in your database.

## What it does

- **Sending identities.** Create an SES identity for a customer's domain with Easy DKIM and a custom MAIL FROM domain, read its verification state, delete it.
- **SES tenants.** Put each customer in its own SES tenant, set its reputation policy, pause and resume it, and read whether SES or you stopped it.
- **A guarded transport.** Every message must be from one of the customer's verified domains in every address it could use (From, Sender, Return-Path, envelope). A message cannot choose its own SES tenant, and the tenant name never appears in the delivered mail.
- **Verified SNS events.** `SnsVerifier` proves an SNS message came from Amazon: region-pinned certificate host, subject and dates, a chain to an Amazon Trust Services or DigiCert root, your topic only, a recent timestamp, and SHA256 signatures (SHA1 optional).
- **DNS records.** The DKIM CNAMEs, the bounce domain's MX and SPF, and a suggested DMARC record, with helpers for providers that add the zone themselves.
- **Domain policy.** Refuses malformed names, free-mail and disposable providers, and domains you reserve.

## Optional: domains, warm-up and reputation in your database (0.2)

Turn on persistence and the package also keeps each owner's sending domains and how they are doing:

- **Sending domains.** `SendingDomains::add()` claims the name, creates the identity, attaches it to the owner's SES tenant and returns the DNS records; `check()`, `remove()` and `dropUnverified()` move it through pending, verified and failed. A reserved or already-existing domain of your own can be adopted as it is and is never deleted at SES.
- **Warm-up.** A new domain's first days have a daily ceiling that steps up (`Warmup::ceiling()`); what to do with bulk mail over it is yours (`Warmup::nextWindow()` names the next day).
- **Reputation.** Hard-bounce and complaint rates over a rolling window warn an owner and then pause it, in your database and at SES, and are tighter while a domain warms up. Only you lift a pause; SES's own pause is followed in both directions.
- **A send gate** that refuses a paused owner, asks your own `SendLimit`s (a plan, an allowance), keeps all owners under the account send rate, and counts what was sent.
- **An events endpoint** for SES delivery events through SNS, verified before anything is read.
- **Events, not notifications.** The package tells you with Laravel events (`DomainChanged`, `DomainStoppedWorking`, `SesEventReceived`, `SendingPaused`, `SendingResumed`, `ReputationWarning`, `MessagesSent`); you send the emails, write the audit log and apply suppressions.
- **Commands** to schedule or run by hand: `ses-tenants:check-domains`, `ses-tenants:sync`, `ses-tenants:release-orphans`, `ses-tenants:pause`, `ses-tenants:resume` and `ses-tenants:warmup`.

```dotenv
SES_TENANTS_PERSISTENCE=true
SES_TENANTS_OWNER_MODEL=App\Models\Team
SES_TENANTS_OWNER_KEY=int          # or uuid, ulid: set it before the first migration
SES_TENANTS_EVENTS_PATH=ses/events # the endpoint SNS posts to; empty registers no route
```

```php
use Odden\SesTenants\Contracts\SesOwner;

class Team extends Model implements SesOwner
{
    public function sesTenantName(): string { return "team-{$this->id}"; }

    public function sesIsRisky(): bool { return $this->onTrial(); }
}
```

```php
$schedule->command('ses-tenants:check-domains')->everyFiveMinutes();
$schedule->command('ses-tenants:sync')->hourly();
$schedule->command('ses-tenants:release-orphans')->daily();
```

Run `php artisan migrate` (or publish the migrations first with `--tag=ses-tenants-migrations`). The tables are `ses_sending_domains`, `ses_usage`, `ses_domain_usage`, `ses_owner_status` and `ses_orphaned_identities`.

## Installation

```bash
composer require getodden/ses-tenants
php artisan vendor:publish --tag=ses-tenants-config
```

Requires PHP 8.3+ and Laravel 13.

## Configuration

```dotenv
SES_TENANTS_ENABLED=true
SES_TENANTS_REGION=us-east-2
SES_TENANTS_KEY=...          # or leave both empty to use the AWS credential chain
SES_TENANTS_SECRET=...
SES_TENANTS_CONFIGURATION_SET=my-set
SES_TENANTS_SNS_TOPICS=arn:aws:sns:us-east-2:123456789012:ses-events
SES_TENANTS_RESERVED_DOMAINS=example.com,example.net
```

The IAM user needs the SES v2 identity, tenant and send actions, plus `sts:GetCallerIdentity`.

## Use

```php
use Odden\SesTenants\Identities\SesIdentities;
use Odden\SesTenants\Dns\DnsRecords;
use Odden\SesTenants\Domains\DomainPolicy;

$domain = app(DomainPolicy::class)->normalize($input)
    ?? throw new RuntimeException(app(DomainPolicy::class)->problem($input));

$identities = app(SesIdentities::class);
$state = $identities->create($domain, "bounce.{$domain}");
$identities->attachToTenant("customer-{$customer->id}", $domain);

$records = DnsRecords::for($domain, $state->dkimTokens, "bounce.{$domain}", config('ses-tenants.region'));
// show $records to the customer; later:
$identities->describe($domain)->isVerified();
```

Send as a customer by defining a mailer that uses the `ses-tenants` transport (for example while that customer is active):

```php
config(['mail.mailers.customer' => [
    'transport' => 'ses-tenants',
    'tenant_name' => "customer-{$customer->id}",
    'domains' => $customer->verifiedDomains(),   // exact names
    'owner_id' => $customer->id,
]]);

Mail::mailer('customer')->to($to)->send($mailable);
```

Plans, allowances and pauses are yours: bind a `Odden\SesTenants\Contracts\SendGate` that throws `SendFailed` from `before()` to refuse a send, and count it in `after()`.

Verify SES events delivered over SNS before reading them:

```php
app(\Odden\SesTenants\Sns\SnsVerifier::class)->verify($request->json()->all()); // throws SnsException
```

## What it does not do yet

- **Chain validation depends on the certificate's own CA Issuers address**, fetched only from `amazontrust.com` and `digicert.com` hosts. A chain that cannot be built is refused.
- **Tested against mocked SES and one real SNS certificate**, not a full production account. It runs in production at [Odden Cloud](https://odden.io).
- The persistence layer is tested on SQLite with a fake SES; run your own migrations check on PostgreSQL or MySQL before relying on it.
- One owner model per application (`persistence.owner_model`).

## License

MIT. Anything published here stays MIT; paid features live in the products built on top, never behind a license check in this package.
