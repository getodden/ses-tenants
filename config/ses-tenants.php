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
];
