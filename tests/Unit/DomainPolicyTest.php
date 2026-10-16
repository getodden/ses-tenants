<?php

use Odden\SesTenants\Domains\DomainPolicy;

it('accepts a domain a customer could own', function (string $input, string $canonical): void {
    $policy = new DomainPolicy(['example-platform.com']);

    expect($policy->problem($input))->toBeNull()->and($policy->normalize($input))->toBe($canonical);
})->with([
    ['Mail.Example.com', 'mail.example.com'],
    ['example.com.', 'example.com'],
    ["  news.example.org\n", 'news.example.org'],
]);

it('refuses what cannot or must not be used', function (string $input): void {
    $policy = new DomainPolicy(['example-platform.com']);

    expect($policy->problem($input))->toBeString()->and($policy->normalize($input))->toBeNull();
})->with([
    'a url' => ['https://example.com/'],
    'an address' => ['me@example.com'],
    'a wildcard' => ['*.example.com'],
    'a single label' => ['localhost'],
    'a reserved tld' => ['mail.corp'],
    'a free-mail provider' => ['gmail.com'],
    'under a free-mail provider' => ['x.gmail.com'],
    'a disposable provider' => ['mailinator.com'],
    'a reserved domain' => ['example-platform.com'],
    'under a reserved domain' => ['mail.example-platform.com'],
    'a trailing newline smuggled past a pattern' => ["a.com\nb.com"],
]);

it('does not treat a name that merely ends the same way as a reserved domain', function (): void {
    expect((new DomainPolicy(['example.com']))->problem('notexample.com'))->toBeNull();
});
