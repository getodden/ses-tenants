<?php

namespace Odden\SesTenants\Sns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Odden\SesTenants\Exceptions\SnsException;
use Throwable;

/**
 * Proves an SNS HTTP message really came from Amazon SNS, for a topic you configured, before anything in it is read:
 *
 *  - the signing certificate must be served by an sns.<region>.amazonaws.com host over HTTPS (never a URL the
 *    sender chose), and its public key must verify the signature over the canonical string of the message;
 *  - the topic must be one we configured (a signed message from somebody else's topic is still refused);
 *  - the timestamp must be recent, and a message id is accepted once (a replay is told apart by the caller).
 *
 * This follows AWS's own "Verifying the signatures of Amazon SNS messages" steps.
 */
final class SnsVerifier
{
    /** An AWS region name: us-east-2, eu-west-1, ap-southeast-3, us-gov-west-1. S3's "sns.s3.amazonaws.com" is not one. */
    private const string REGION = '[a-z]{2}(?:-[a-z]+)+-[0-9]+';

    private const string CERT_HOST = '/^sns\.'.self::REGION.'\.amazonaws\.com(\.cn)?$/';

    /** The name on Amazon's signing certificate: sns.amazonaws.com (or a regional form of it). */
    private const string CERT_SUBJECT = '/^sns\.(?:'.self::REGION.'\.)?amazonaws\.com(\.cn)?$/';

    /** @var list<string> What a Notification is signed over, in order. */
    private const array NOTIFICATION_KEYS = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];

    /** @var list<string> What a (Un)SubscriptionConfirmation is signed over, in order. */
    private const array CONFIRMATION_KEYS = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

    /**
     * @param  list<string>  $topics  The topic ARNs we accept.
     * @param  bool  $requireSignatureV2  Refuse SignatureVersion 1 (SHA1), which a topic signs with until it is set to version 2.
     * @param  bool  $verifyChain  Require the signing certificate to chain to a root in $chainRoots.
     * @param  string|null  $chainRoots  A PEM file of trusted roots; null is the Amazon Trust Services and DigiCert roots shipped here.
     * @param  list<string>  $issuerHosts  The only hosts (suffix match) a certificate's CA Issuers address may be fetched from.
     */
    public function __construct(
        private readonly array $topics,
        private readonly int $maxAgeSeconds = 86400,
        private readonly bool $requireSignatureV2 = false,
        private readonly bool $verifyChain = true,
        private readonly ?string $chainRoots = null,
        private readonly array $issuerHosts = ['amazontrust.com', 'digicert.com'],
    ) {}

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws SnsException
     */
    public function verify(array $message): void
    {
        $type = $this->string($message, 'Type');

        if (! in_array($type, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            throw new SnsException('Unknown message type.');
        }

        if (! in_array($this->string($message, 'TopicArn'), $this->topics, true)) {
            throw new SnsException('Message is for a topic that is not ours.');
        }

        $this->checkTimestamp($this->string($message, 'Timestamp'));

        $algorithm = match ($this->string($message, 'SignatureVersion')) {
            // SHA1 is what a topic signs with unless it is set to version 2 (aws sns set-topic-attributes ... SignatureVersion 2).
            // It is accepted until that is done and $requireSignatureV2 is turned on.
            '1' => $this->requireSignatureV2 ? throw new SnsException('Signature version 1 is not accepted.') : OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => throw new SnsException('Unsupported signature version.'),
        };

        $signature = base64_decode($this->string($message, 'Signature'), true);

        if ($signature === false || $signature === '') {
            throw new SnsException('Signature is not valid base64.');
        }

        $key = openssl_pkey_get_public($this->certificate($this->string($message, 'SigningCertURL'), self::regionOf($this->string($message, 'TopicArn'))));

        if ($key === false || openssl_verify($this->stringToSign($message, $type), $signature, $key, $algorithm) !== 1) {
            throw new SnsException('Signature does not match.');
        }
    }

    /**
     * Whether an https URL is one of Amazon SNS's own (used for the certificate and for confirming a subscription).
     */
    public static function isSnsUrl(string $url, ?string $region = null): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($region !== null) {
            return $host === "sns.{$region}.amazonaws.com" || $host === "sns.{$region}.amazonaws.com.cn";
        }

        return preg_match(self::CERT_HOST, $host) === 1;
    }

    /**
     * The region in a topic ARN (arn:aws:sns:us-east-2:123456789012:name), or null.
     */
    private static function regionOf(string $topicArn): ?string
    {
        return preg_match('/^arn:aws(?:-[a-z]+)*:sns:('.self::REGION.'):[0-9]{12}:[A-Za-z0-9_-]+$/', $topicArn, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function stringToSign(array $message, string $type): string
    {
        $signed = '';

        foreach ($type === 'Notification' ? self::NOTIFICATION_KEYS : self::CONFIRMATION_KEYS as $key) {
            if (isset($message[$key]) && is_string($message[$key])) {
                $signed .= $key."\n".$message[$key]."\n";
            }
        }

        return $signed;
    }

    private function certificate(string $url, ?string $region): string
    {
        $parts = parse_url($url);

        // The certificate comes from SNS in the region of the configured topic, nothing else, and with no query, fragment or port.
        if ($region === null || ! self::isSnsUrl($url, $region) || ! preg_match('#^/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', (string) ($parts['path'] ?? '')) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new SnsException('Signing certificate is not served by Amazon SNS.');
        }

        return Cache::remember('ses-tenants:sns-cert:'.hash('sha256', $url), 86400, function () use ($url): string {
            try {
                $response = Http::timeout(5)->get($url);
            } catch (Throwable) {
                throw new SnsException('Signing certificate could not be fetched.');
            }

            if (! $response->successful() || openssl_x509_read($response->body()) === false) {
                throw new SnsException('Signing certificate could not be fetched.');
            }

            // The name on it and its dates, so a file that is not Amazon's SNS certificate, or one that has expired, is not
            // trusted just because it was served from the right host. The chain to a root we hold is checked below.
            $info = openssl_x509_parse($response->body());

            if ($info === false) {
                throw new SnsException('Signing certificate could not be read.');
            }

            $subject = is_string($info['subject']['CN'] ?? null) ? strtolower($info['subject']['CN']) : '';

            if (preg_match(self::CERT_SUBJECT, $subject) !== 1 || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > now()->getTimestamp() || ($info['validTo_time_t'] ?? 0) < now()->getTimestamp()) {
                throw new SnsException('Signing certificate is not Amazon SNS\'s.');
            }

            if ($this->verifyChain) {
                $this->assertChain($response->body(), $info);
            }

            return $response->body();
        });
    }

    /**
     * The certificate must chain to a root we hold. SNS serves the leaf alone, so the intermediate comes from the leaf's own
     * CA Issuers address, and only from the issuer hosts we name: an address in a certificate is data, never somewhere to go.
     *
     * @param  array<string, mixed>  $info  The parsed leaf certificate.
     *
     * @throws SnsException
     */
    private function assertChain(string $leaf, array $info): void
    {
        $roots = $this->chainRoots ?? dirname(__DIR__, 2).'/resources/sns/trust-roots.pem';

        if (! is_file($roots)) {
            throw new SnsException('Signing certificate chain could not be checked.');
        }

        $intermediates = '';
        $current = $info;

        // A chain of more than three intermediates is not Amazon's.
        for ($depth = 0; $depth < 3; $depth++) {
            $issuer = $this->issuerCertificate((string) ($current['extensions']['authorityInfoAccess'] ?? ''));

            if ($issuer === null) {
                break;
            }

            $intermediates .= $issuer."\n";
            $parsed = openssl_x509_parse($issuer);

            if ($parsed === false) {
                break;
            }

            $current = $parsed;
        }

        $file = tempnam(sys_get_temp_dir(), 'sns-chain');

        if ($file === false) {
            throw new SnsException('Signing certificate chain could not be checked.');
        }

        try {
            file_put_contents($file, $intermediates);

            if (openssl_x509_checkpurpose($leaf, X509_PURPOSE_ANY, [$roots], $intermediates === '' ? null : $file) !== true) {
                throw new SnsException('Signing certificate does not chain to a trusted root.');
            }
        } finally {
            @unlink($file);
        }
    }

    /**
     * The issuer's certificate, as PEM, from the "CA Issuers - URI:" line of an Authority Information Access extension; null
     * when there is none (the issuer is then expected to be one of our roots) or it is not on an allowed host.
     */
    private function issuerCertificate(string $authorityInfoAccess): ?string
    {
        if (preg_match('#CA Issuers - URI:(\S+)#', $authorityInfoAccess, $match) !== 1) {
            return null;
        }

        $parts = parse_url($match[1]);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', $this->issuerHosts);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        foreach ($allowed as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return Cache::remember('ses-tenants:sns-issuer:'.hash('sha256', $match[1]), 86400, function () use ($match): ?string {
                    try {
                        $body = Http::timeout(5)->get($match[1])->body();
                    } catch (Throwable) {
                        return null;
                    }

                    // CA Issuers files are DER or PEM.
                    $pem = str_contains($body, '-----BEGIN CERTIFICATE-----')
                        ? $body
                        : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($body), 64, "\n")."-----END CERTIFICATE-----\n";

                    return openssl_x509_parse($pem) === false ? null : $pem;
                });
            }
        }

        return null;
    }

    private function checkTimestamp(string $timestamp): void
    {
        try {
            $sent = Carbon::parse($timestamp);
        } catch (Throwable) {
            throw new SnsException('Timestamp is not valid.');
        }

        if (abs(now()->diffInSeconds($sent, false)) > $this->maxAgeSeconds) {
            throw new SnsException('Message is too old (or from the future).');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function string(array $message, string $key): string
    {
        $value = $message[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new SnsException("Missing {$key}.");
        }

        return $value;
    }
}
