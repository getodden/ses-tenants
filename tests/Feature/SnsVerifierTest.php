<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Odden\SesTenants\Exceptions\SnsException;
use Odden\SesTenants\Sns\SnsVerifier;

/*
 * What makes an SNS message ours: the topic, the certificate's address and name and dates, and the signature over it.
 */

require_once __DIR__.'/../Support/sns-helpers.php';

beforeEach(function (): void {
    Cache::flush();
    snsvTrust(snsvAuthority(fresh: true));
});

it('accepts a message signed by the certificate Amazon serves for our topic\'s region', function (): void {
    [$key, $pem] = snsvCertificate();
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);

    snsvVerify(snsvMessage($key));

    expect(true)->toBeTrue();
});

it('accepts the regional name on a certificate too', function (): void {
    [$key, $pem] = snsvCertificate('sns.us-east-2.amazonaws.com');
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);

    snsvVerify(snsvMessage($key));

    expect(true)->toBeTrue();
});

it('refuses a certificate address that is not SNS in the topic region, however close it looks', function (string $url): void {
    [$key, $pem] = snsvCertificate();
    Http::fake(['*' => Http::response($pem)]);

    expect(fn () => snsvVerify(snsvMessage($key, ['SigningCertURL' => $url])))->toThrow(SnsException::class);
    Http::assertNothingSent();
})->with([
    'an S3 bucket called sns' => ['https://sns.s3.amazonaws.com/SimpleNotificationService-abc123.pem'],
    'an S3 website bucket' => ['https://sns.s3-website-us-east-2.amazonaws.com/SimpleNotificationService-abc123.pem'],
    'another region' => ['https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-abc123.pem'],
    'user information' => ['https://evil@sns.us-east-2.amazonaws.com/SimpleNotificationService-abc123.pem'],
    'a port' => ['https://sns.us-east-2.amazonaws.com:8443/SimpleNotificationService-abc123.pem'],
    'a query' => ['https://sns.us-east-2.amazonaws.com/SimpleNotificationService-abc123.pem?x=1'],
    'a fragment' => ['https://sns.us-east-2.amazonaws.com/SimpleNotificationService-abc123.pem#x'],
    'plain http' => ['http://sns.us-east-2.amazonaws.com/SimpleNotificationService-abc123.pem'],
    'another file name' => ['https://sns.us-east-2.amazonaws.com/cert.pem'],
    'another host with the name inside' => ['https://sns.us-east-2.amazonaws.com.evil.example/SimpleNotificationService-abc123.pem'],
]);

it('refuses a certificate that is not named for SNS, or has expired', function (string $subject, int $travelDays): void {
    [$key, $pem] = snsvCertificate($subject, 1);
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);

    $this->travel($travelDays)->days();

    // The message is dated after the travel so only the certificate is at fault.
    expect(fn () => snsvVerify(snsvMessage($key)))->toThrow(SnsException::class);
})->with([
    'a certificate for another name' => ['attacker.example.com', 0],
    'a bucket name' => ['sns.s3.amazonaws.com', 0],
    'an expired certificate' => ['sns.amazonaws.com', 3],
]);

it('refuses a signature made with another key, a changed message, and another topic', function (): void {
    [$key, $pem] = snsvCertificate();
    [$other] = snsvCertificate();
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);

    $forged = snsvMessage($other);
    $changed = snsvMessage($key);
    $changed['Message'] = '{"a":2}';
    $foreign = snsvMessage($key, ['TopicArn' => 'arn:aws:sns:us-east-2:999999999999:theirs']);

    foreach ([$forged, $changed, $foreign] as $message) {
        expect(fn () => snsvVerify($message))->toThrow(SnsException::class);
    }
});

it('accepts signature version 1 until it is turned off, and then refuses it', function (): void {
    [$key, $pem] = snsvCertificate();
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);
    $v1 = snsvMessage($key, [], '1');

    snsvVerify($v1);

    config(['ses-tenants.sns.require_signature_v2' => true]);
    expect(fn () => snsvVerify($v1))->toThrow(SnsException::class, 'version 1');

    snsvVerify(snsvMessage($key, [], '2'));
});

it('refuses a certificate that does not chain to a root we trust', function (): void {
    $stranger = snsvAuthority();
    [$key, $pem] = snsvCertificate(issuer: $stranger);
    snsvTrust(snsvAuthority(fresh: true));
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);

    expect(fn () => snsvVerify(snsvMessage($key)))->toThrow(SnsException::class, 'trusted root');
});

it('builds the chain through an intermediate fetched from the certificate\'s own issuer address', function (): void {
    $root = snsvAuthority();
    $url = 'http://crt.r2m02.amazontrust.com/r2m02.cer';

    $iKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $iCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Intermediate'], $iKey), $root['cert'], $root['key'], 365, snsvConfig('v3_ca'));
    openssl_x509_export($iCert, $iPem);
    $der = base64_decode(trim(preg_replace('/-----[A-Z ]+-----/', '', $iPem)));

    [$key, $pem] = snsvCertificate(issuer: ['key' => $iKey, 'cert' => $iCert], issuerUrl: $url);
    Http::fake([SNS_TEST_CERT => Http::response($pem), $url => Http::response($der)]);

    snsvVerify(snsvMessage($key));

    expect(true)->toBeTrue();
});

it('does not go to an issuer address that is not on an allowed host', function (): void {
    $root = snsvAuthority();
    $url = 'http://169.254.169.254/latest/meta-data/ca.cer';

    [$key, $pem] = snsvCertificate(issuerUrl: $url);
    // Signed by the root directly, so it would verify without an intermediate: the address must still never be fetched.
    Http::fake([SNS_TEST_CERT => Http::response($pem), '*' => Http::response('x')]);

    snsvVerify(snsvMessage($key));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
});

it('skips the chain check only when it is turned off', function (): void {
    [$key, $pem] = snsvCertificate(issuer: snsvAuthority());
    snsvTrust(snsvAuthority(fresh: true));
    Http::fake([SNS_TEST_CERT => Http::response($pem)]);
    config(['ses-tenants.sns.verify_chain' => false]);

    snsvVerify(snsvMessage($key));

    expect(true)->toBeTrue();
});

it('judges a subscription address by the same rules', function (): void {
    expect(SnsVerifier::isSnsUrl('https://sns.us-east-2.amazonaws.com/?Action=ConfirmSubscription&Token=x'))->toBeTrue()
        ->and(SnsVerifier::isSnsUrl('https://sns.us-east-2.amazonaws.com/', 'us-east-2'))->toBeTrue()
        ->and(SnsVerifier::isSnsUrl('https://sns.us-east-2.amazonaws.com/', 'eu-west-1'))->toBeFalse()
        ->and(SnsVerifier::isSnsUrl('https://sns.s3.amazonaws.com/'))->toBeFalse()
        ->and(SnsVerifier::isSnsUrl('https://u:p@sns.us-east-2.amazonaws.com/'))->toBeFalse()
        ->and(SnsVerifier::isSnsUrl('https://u@sns.us-east-2.amazonaws.com/'))->toBeFalse()
        ->and(SnsVerifier::isSnsUrl('https://sns.us-east-2.amazonaws.com:444/'))->toBeFalse();
});
