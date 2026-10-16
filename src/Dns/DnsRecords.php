<?php

namespace Odden\SesTenants\Dns;

use Odden\SesTenants\Identities\IdentityState;

/**
 * The DNS records a sending domain needs at SES, and helpers for telling a person which part of a record name to enter.
 */
final class DnsRecords
{
    /**
     * The records to add: the three DKIM CNAMEs and the bounce domain's MX and SPF are required; DMARC is advised.
     *
     * @param  list<string>  $dkimTokens  From {@see IdentityState::$dkimTokens}.
     * @return list<array{type: string, name: string, value: string, purpose: string, required: bool}>
     */
    public static function for(string $domain, array $dkimTokens, string $mailFromDomain, string $region): array
    {
        $records = [];

        foreach ($dkimTokens as $token) {
            $records[] = ['type' => 'CNAME', 'name' => "{$token}._domainkey.{$domain}", 'value' => "{$token}.dkim.amazonses.com", 'purpose' => 'DKIM', 'required' => true];
        }

        $records[] = ['type' => 'MX', 'name' => $mailFromDomain, 'value' => "10 feedback-smtp.{$region}.amazonses.com", 'purpose' => 'Bounces', 'required' => true];
        $records[] = ['type' => 'TXT', 'name' => $mailFromDomain, 'value' => 'v=spf1 include:amazonses.com ~all', 'purpose' => 'Bounces (SPF)', 'required' => true];
        $records[] = ['type' => 'TXT', 'name' => "_dmarc.{$domain}", 'value' => 'v=DMARC1; p=none;', 'purpose' => 'DMARC (recommended; skip it if you already have one)', 'required' => false];

        return $records;
    }

    /**
     * The domain most DNS providers manage a zone for, for telling a customer which part of a record name to leave off
     * (many providers add it themselves). A guess from the last two labels, or three under a common second-level
     * suffix (co.uk), which is right for nearly all domains and only ever used as a hint.
     */
    public static function zoneGuess(string $domain): string
    {
        $labels = explode('.', strtolower($domain));
        $take = count($labels) >= 3 && strlen($labels[count($labels) - 1]) === 2 && in_array($labels[count($labels) - 2], ['co', 'com', 'org', 'net', 'ac', 'gov', 'edu', 'ne', 'or'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -min($take, count($labels))));
    }

    /**
     * A record's name with the zone left off, as providers that add the zone themselves want it ("@" for the zone itself).
     */
    public static function shortName(string $name, string $domain): string
    {
        $zone = self::zoneGuess($domain);
        $name = strtolower($name);

        if ($name === $zone) {
            return '@';
        }

        return str_ends_with($name, '.'.$zone) ? substr($name, 0, -strlen($zone) - 1) : $name;
    }
}
