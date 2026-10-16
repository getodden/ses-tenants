<?php

use Odden\SesTenants\Dns\DnsRecords;

it('lists the DKIM, bounce and DMARC records for a sending domain', function (): void {
    $records = DnsRecords::for('mail.example.com', ['a1', 'b2', 'c3'], 'bounce.mail.example.com', 'us-east-2');

    expect($records)->toHaveCount(6)
        ->and($records[0])->toBe(['type' => 'CNAME', 'name' => 'a1._domainkey.mail.example.com', 'value' => 'a1.dkim.amazonses.com', 'purpose' => 'DKIM', 'required' => true])
        ->and($records[3])->toMatchArray(['type' => 'MX', 'name' => 'bounce.mail.example.com', 'value' => '10 feedback-smtp.us-east-2.amazonses.com', 'required' => true])
        ->and($records[4])->toMatchArray(['type' => 'TXT', 'value' => 'v=spf1 include:amazonses.com ~all', 'required' => true])
        ->and($records[5])->toMatchArray(['name' => '_dmarc.mail.example.com', 'required' => false]);
});

it('guesses the zone and shortens record names to it', function (): void {
    expect(DnsRecords::zoneGuess('mail.example.com'))->toBe('example.com')
        ->and(DnsRecords::zoneGuess('mail.example.co.uk'))->toBe('example.co.uk')
        ->and(DnsRecords::shortName('a1._domainkey.mail.example.com', 'mail.example.com'))->toBe('a1._domainkey.mail')
        ->and(DnsRecords::shortName('example.com', 'mail.example.com'))->toBe('@')
        ->and(DnsRecords::shortName('other.test', 'mail.example.com'))->toBe('other.test');
});
