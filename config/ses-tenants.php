<?php

return [
    /*
    | Off until the AWS account is configured. While off, no identity can be created and nothing is sent through the
    | "ses-tenants" mailer.
    */
    'enabled' => (bool) env('SES_TENANTS_ENABLED', false),

    /*
    | The AWS region and credentials. The credentials belong to an IAM user limited to the SES actions the package uses;
    | leave both empty to use the AWS default credential chain (an instance or task role).
    */
    'region' => env('SES_TENANTS_REGION', env('AWS_DEFAULT_REGION')),
    'key' => env('SES_TENANTS_KEY'),
    'secret' => env('SES_TENANTS_SECRET'),

    /*
    | The SES configuration set every message goes through (one set, shared by all tenants), and the subdomain label each
    | sending domain gets for its custom MAIL FROM (bounce.example.com).
    */
    'configuration_set' => env('SES_TENANTS_CONFIGURATION_SET'),
    'mail_from_label' => 'bounce',

    /*
    | Domains (and everything under them) that customers can never use for sending: the application's own names.
    */
    'reserved_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('SES_TENANTS_RESERVED_DOMAINS', ''))))),

    /*
    | Verifying the SNS messages SES delivery events arrive in.
    |
    | topics: the topic ARNs accepted (a signed message from anybody else's topic is refused).
    | require_signature_v2: refuse SignatureVersion 1 (SHA1). A topic signs with it until set to version 2:
    |   aws sns set-topic-attributes --topic-arn ... --attribute-name SignatureVersion --attribute-value 2
    | verify_chain: the signing certificate must chain to a root in chain_roots (default: the Amazon Trust Services and
    |   DigiCert roots in resources/sns/trust-roots.pem). The intermediate is fetched from the certificate's own CA
    |   Issuers address, only from the hosts in chain_issuer_hosts, and a chain that cannot be built is refused.
    */
    'sns' => [
        'topics' => array_values(array_filter(array_map('trim', explode(',', (string) env('SES_TENANTS_SNS_TOPICS', ''))))),
        'max_age_seconds' => 86400,
        'require_signature_v2' => (bool) env('SES_TENANTS_SNS_REQUIRE_V2', false),
        'verify_chain' => (bool) env('SES_TENANTS_SNS_VERIFY_CHAIN', true),
        'chain_roots' => env('SES_TENANTS_SNS_CHAIN_ROOTS'),
        'chain_issuer_hosts' => ['amazontrust.com', 'digicert.com'],
    ],

    /*
    | Layer 2: the package keeps sending domains, usage, warm-up and reputation in your database. Off by default; turn it on
    | and run the migrations (php artisan migrate, or publish them first: --tag=ses-tenants-migrations).
    |
    | owner_model: the Eloquent model that owns sending domains (a customer, a workspace). It implements SesOwner.
    | owner_key: the type of its key, for the morph columns: int, uuid or ulid. Set it before the first migration.
    */
    'persistence' => [
        'enabled' => (bool) env('SES_TENANTS_PERSISTENCE', false),
        'owner_model' => env('SES_TENANTS_OWNER_MODEL'),
        'owner_key' => env('SES_TENANTS_OWNER_KEY', 'int'),
    ],

    /*
    | Domains an owner (by key) may take over as they are although they are reserved or already have an identity at SES: your
    | own names, for your own account. They are never deleted at SES. Shape: [ownerKey => ['example.com']].
    */
    'adopt' => [],

    'domains' => [
        'max_per_owner' => 3,
        // Hours a domain may wait for its DNS records before it is marked failed (SES itself gives up after 72).
        'pending_hours' => 72,
        // Days a domain that was never verified may hold its name. SES stops waiting after 72 hours, so a claim this old is
        // abandoned (or squatted): dropping it frees the name for the domain's real owner.
        'drop_unverified_days' => 7,
        // Removing an identity: tries, and milliseconds between them (SES lags a moment after a tenant association goes).
        'release_attempts' => 4,
        'release_wait_ms' => 3000,
    ],

    /*
    | The reputation policy each owner's SES tenant gets (SES pauses a tenant by itself under it): "risky" while the owner
    | is risky or one of its domains is warming up, "normal" otherwise. Values: standard (pause on high-severity findings),
    | strict (pause on any finding), none.
    */
    'ses_policy' => ['normal' => 'standard', 'risky' => 'strict'],

    // All sends together, per second: keep it under the SES account's own limit.
    'max_per_second' => 12,
    'rate_wait_tries' => 50,

    /*
    | A new domain's first days are limited so it builds a reputation without hurting the account's: the most bulk messages it
    | may send on its first day, its second, and so on, then no daily limit. Rates are judged more tightly while it is inside it.
    */
    'warmup' => [
        'enabled' => (bool) env('SES_TENANTS_WARMUP', true),
        'steps' => [200, 500, 1000, 2000, 5000, 10000, 20000],
        'reputation' => [
            'window_days' => 7, 'min_sends' => 100,
            'warn_bounce' => 0.01, 'warn_complaint' => 0.0003,
            'pause_bounce' => 0.02, 'pause_complaint' => 0.0005,
        ],
    ],

    /*
    | Rates over a rolling window, counted once an owner has sent min_sends in it. These sit well under the levels at which SES
    | itself puts an account under review (check the AWS documentation for the current ones).
    */
    'reputation' => [
        'window_days' => 7, 'min_sends' => 500,
        'warn_bounce' => 0.02, 'warn_complaint' => 0.0005,
        'pause_bounce' => 0.04, 'pause_complaint' => 0.001,
    ],

    /*
    | Your own limits on sending (a plan, an allowance, a trial): classes implementing SendLimit, checked before every send.
    */
    'limits' => [],

    /*
    | The endpoint SES delivery events arrive at through SNS. Leave the path empty to register no route and call
    | SesEventsController yourself. The route has no middleware by default, so no CSRF check applies.
    */
    'events' => [
        'path' => env('SES_TENANTS_EVENTS_PATH', ''),
        'middleware' => [],
    ],
];
