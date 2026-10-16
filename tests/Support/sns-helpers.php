<?php

use Illuminate\Support\Str;
use Odden\SesTenants\Sns\SnsVerifier;

const SNS_TEST_TOPIC = 'arn:aws:sns:us-east-2:123456789012:odden-inbound';
const SNS_TEST_CERT = 'https://sns.us-east-2.amazonaws.com/SimpleNotificationService-abc123.pem';

/**
 * A test certificate authority, made once per test and trusted through the chain_roots setting.
 *
 * @return array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate}
 */
function snsvAuthority(bool $fresh = false): array
{
    static $authority = null;

    if ($authority === null || $fresh) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Test Root CA'], $key);
        $authority = ['key' => $key, 'cert' => openssl_csr_sign($csr, null, $key, 365, snsvConfig('v3_ca'))];
    }

    return $authority;
}

/**
 * An openssl config file with a CA section and a leaf section, the latter optionally naming an issuer address (AIA).
 *
 * @return array<string, mixed>
 */
function snsvConfig(string $extensions, ?string $issuerUrl = null): array
{
    $file = tempnam(sys_get_temp_dir(), 'snsv');
    file_put_contents($file, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=keyCertSign,cRLSign\n"
        ."[v3_leaf]\nbasicConstraints=CA:FALSE\n".($issuerUrl === null ? '' : "authorityInfoAccess=caIssuers;URI:{$issuerUrl}\n"));

    return ['config' => $file, 'x509_extensions' => $extensions, 'digest_alg' => 'sha256'];
}

/**
 * A certificate and the key behind it, signed by the test authority (or the one given).
 *
 * @param  array{key: OpenSSLAsymmetricKey, cert: OpenSSLCertificate}|null  $issuer
 * @return array{0: OpenSSLAsymmetricKey, 1: string}
 */
function snsvCertificate(string $subject = 'sns.amazonaws.com', int $days = 30, ?array $issuer = null, ?string $issuerUrl = null): array
{
    $issuer ??= snsvAuthority();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => $subject], $key);
    $cert = openssl_csr_sign($csr, $issuer['cert'], $issuer['key'], $days, snsvConfig('v3_leaf', $issuerUrl));
    openssl_x509_export($cert, $pem);

    return [$key, $pem];
}

/**
 * The test authority's certificate as a trust file, which chain_roots then names.
 */
function snsvTrust(array $authority): void
{
    $file = tempnam(sys_get_temp_dir(), 'snsv-roots');
    openssl_x509_export($authority['cert'], $pem);
    file_put_contents($file, $pem);

    config(['ses-tenants.sns.chain_roots' => $file]);
}

/**
 * A signed Notification, as SNS sends it.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function snsvMessage(OpenSSLAsymmetricKey $key, array $overrides = [], string $version = '2'): array
{
    $message = $overrides + [
        'Type' => 'Notification', 'MessageId' => (string) Str::uuid(), 'TopicArn' => SNS_TEST_TOPIC, 'Message' => '{"a":1}',
        'Timestamp' => now()->toIso8601ZuluString('millisecond'), 'SignatureVersion' => $version, 'SigningCertURL' => SNS_TEST_CERT,
    ];

    $toSign = '';
    $fields = $message['Type'] === 'Notification'
        ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
        : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    foreach ($fields as $field) {
        isset($message[$field]) && $toSign .= $field."\n".$message[$field]."\n";
    }

    openssl_sign($toSign, $signature, $key, $version === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);

    return $message + ['Signature' => base64_encode($signature)];
}

function snsvVerify(array $message): void
{
    config(['ses-tenants.sns.topics' => [SNS_TEST_TOPIC]]);
    app()->forgetInstance(SnsVerifier::class);

    app(SnsVerifier::class)->verify($message);
}
