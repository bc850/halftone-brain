<?php

use App\Support\Quotes\Security\QuoteCustomerAccessTokenGenerator;

test('token generator creates url-safe random tokens and sha256 hashes', function () {
    $generator = new QuoteCustomerAccessTokenGenerator;

    $raw = $generator->generateRaw();
    $hash = $generator->hashToken($raw);

    expect($raw)->not->toBe('')
        ->and(preg_match('/^[A-Za-z0-9\-_]+$/', $raw))->toBe(1)
        ->and(strlen($hash))->toBe(64)
        ->and($hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($generator->verify($raw, $hash))->toBeTrue()
        ->and($generator->verify($raw.'x', $hash))->toBeFalse()
        ->and($generator->verify('', $hash))->toBeFalse();

    $second = $generator->generateRaw();
    expect($second)->not->toBe($raw);
});

test('token generator evaluates expiry closed on the boundary', function () {
    $generator = new QuoteCustomerAccessTokenGenerator;
    $expiresAt = now()->addMinute();

    expect($generator->isExpired($expiresAt, now()))->toBeFalse()
        ->and($generator->isExpired($expiresAt, $expiresAt))->toBeTrue()
        ->and($generator->isExpired($expiresAt, now()->addMinutes(2)))->toBeTrue();
});

test('token generator rejects undersized entropy', function () {
    expect(fn () => (new QuoteCustomerAccessTokenGenerator)->generateRaw(8))
        ->toThrow(InvalidArgumentException::class);
});
