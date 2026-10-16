<?php

namespace Odden\SesTenants\Domains;

/**
 * Which domains may be used for sending: a well-formed name the customer could own, not a free-mail or disposable
 * provider, and not one the application reserves for itself.
 */
final class DomainPolicy
{
    private const array RESERVED_TLDS = ['localhost', 'local', 'internal', 'test', 'invalid', 'example', 'onion', 'lan', 'home', 'corp', 'arpa'];

    /** Free-mail providers: nobody can sign mail for these, and their reputation is not ours to borrow. */
    private const array FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'aol.com', 'icloud.com',
        'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'pm.me', 'zoho.com', 'gmx.com', 'gmx.net', 'yandex.com', 'yandex.ru', 'mail.com', 'fastmail.com',
    ];

    private const array DISPOSABLE = [
        'mailinator.com', 'tempmail.com', '10minutemail.com', 'yopmail.com', 'sharklasers.com', 'guerrillamail.com', 'dispostable.com',
        'trashmail.com', 'throwawaymail.com', 'maildrop.cc', 'getairmail.com', 'temp-mail.org',
    ];

    /**
     * @param  list<string>  $reserved  Domains (and everything under them) the application keeps for itself.
     */
    public function __construct(private readonly array $reserved = []) {}

    /**
     * The lower-case ASCII form of a name, without any check of whether it may be used.
     */
    public static function canonical(string $value): string
    {
        // Dots and white space come off the end together: "a.com\n." is "a.com" and not "a.com\n" (a trailing newline that a "$" in a
        // pattern would let through).
        $domain = rtrim(strtolower(trim($value)), " \t\n\r\0\x0B.");

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $domain) === 1) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $domain = is_string($ascii) ? $ascii : $domain;
        }

        return $domain;
    }

    /**
     * The canonical domain, or null if it can't be used.
     */
    public function normalize(string $input): ?string
    {
        return $this->problem($input) === null ? self::canonical($input) : null;
    }

    public static function isFreeMail(string $domain): bool
    {
        return self::under(strtolower(trim($domain)), self::FREE_MAIL);
    }

    public static function isDisposable(string $domain): bool
    {
        return self::under(strtolower(trim($domain)), self::DISPOSABLE);
    }

    /**
     * Why a domain can't be used for sending, in words for a person, or null if it can.
     */
    public function problem(string $input): ?string
    {
        $domain = self::canonical($input);

        if ($domain === '' || str_contains($domain, '/') || str_contains($domain, ':') || str_contains($domain, '@') || str_contains($domain, ' ') || str_contains($domain, '*')) {
            return 'Enter just the domain name, such as mail.example.com or example.com.';
        }

        if (strlen($domain) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain)) {
            return 'That is not a valid domain name.';
        }

        if (in_array(substr($domain, (int) strrpos($domain, '.') + 1), self::RESERVED_TLDS, true)) {
            return 'That domain can\'t be used.';
        }

        if (self::under($domain, array_map('strtolower', $this->reserved))) {
            return 'That domain can\'t be used for sending.';
        }

        if (self::under($domain, [...self::FREE_MAIL, ...self::DISPOSABLE])) {
            return 'Use a domain your organization owns, not a free-mail or disposable provider.';
        }

        return null;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function under(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            if ($candidate !== '' && ($domain === $candidate || str_ends_with($domain, '.'.$candidate))) {
                return true;
            }
        }

        return false;
    }
}
