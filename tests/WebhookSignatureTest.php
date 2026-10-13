<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Exceptions\WebhookSignatureVerificationException;
use Taxora\Sdk\Webhooks\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const NOW = 1700000000;
    private const BODY = '{"id":"evt_1"}';

    public function testSignatureMatchesSharedBackendVector(): void
    {
        // Same vector as taxora-backend WebhookSignatureServiceTest and the Node SDK.
        self::assertSame(
            'c89214b5b5da833daed6f0b8c5bb6bd58cea9022bd80ccc78230f3942d632925',
            WebhookSignature::computeSignature(self::BODY, self::NOW, self::SECRET),
        );
    }

    public function testAcceptsValidSignatureWithinTolerance(): void
    {
        $header = WebhookSignature::generateHeader(self::BODY, self::SECRET, self::NOW);

        self::assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, now: self::NOW + 299));
        self::assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, now: self::NOW - 299));
    }

    public function testRejectsTimestampOutsideTolerance(): void
    {
        $header = WebhookSignature::generateHeader(self::BODY, self::SECRET, self::NOW);

        self::assertReason(
            WebhookSignatureVerificationException::REASON_TIMESTAMP_OUTSIDE_TOLERANCE,
            fn () => WebhookSignature::verify(self::BODY, $header, self::SECRET, now: self::NOW + 301),
        );
        self::assertReason(
            WebhookSignatureVerificationException::REASON_TIMESTAMP_OUTSIDE_TOLERANCE,
            fn () => WebhookSignature::verify(self::BODY, $header, self::SECRET, tolerance: 60, now: self::NOW + 61),
        );
        self::assertTrue(WebhookSignature::verify(self::BODY, $header, self::SECRET, tolerance: 0, now: self::NOW + 99999));
    }

    public function testRejectsTamperedBodyWrongSecretReencodedJsonAndSwappedTimestamp(): void
    {
        $body = '{ "id": "evt_1", "url": "https://x.test/a" }';
        $header = WebhookSignature::generateHeader($body, self::SECRET, self::NOW);
        $reason = WebhookSignatureVerificationException::REASON_NO_MATCHING_SIGNATURE;

        self::assertReason($reason, fn () => WebhookSignature::verify($body . ' ', $header, self::SECRET, now: self::NOW));
        self::assertReason($reason, fn () => WebhookSignature::verify($body, $header, 'whsec_other', now: self::NOW));
        self::assertReason($reason, fn () => WebhookSignature::verify(
            (string) json_encode(json_decode($body, true)),
            $header,
            self::SECRET,
            now: self::NOW,
        ));
        $swapped = str_replace('t=' . self::NOW, 't=' . (self::NOW + 10), $header);
        self::assertReason($reason, fn () => WebhookSignature::verify($body, $swapped, self::SECRET, now: self::NOW));
    }

    public function testAcceptsAnyOfSeveralSignaturesAndSecrets(): void
    {
        $rotating = WebhookSignature::generateHeader(self::BODY, ['whsec_new', 'whsec_old'], self::NOW);
        self::assertTrue(WebhookSignature::verify(self::BODY, $rotating, 'whsec_old', now: self::NOW));
        self::assertTrue(WebhookSignature::verify(self::BODY, $rotating, 'whsec_new', now: self::NOW));

        $single = WebhookSignature::generateHeader(self::BODY, 'whsec_b', self::NOW);
        self::assertTrue(WebhookSignature::verify(self::BODY, $single, ['whsec_a', 'whsec_b'], now: self::NOW));
    }

    public function testRejectsMissingOrMalformedHeaderAndMissingSecret(): void
    {
        $valid = WebhookSignature::generateHeader(self::BODY, self::SECRET, self::NOW);

        self::assertReason(WebhookSignatureVerificationException::REASON_MISSING_HEADER, fn () => WebhookSignature::verify(self::BODY, null, self::SECRET));
        self::assertReason(WebhookSignatureVerificationException::REASON_MISSING_HEADER, fn () => WebhookSignature::verify(self::BODY, ' ', self::SECRET));
        self::assertReason(WebhookSignatureVerificationException::REASON_MALFORMED_HEADER, fn () => WebhookSignature::verify(self::BODY, 'sha256=abc', self::SECRET));
        self::assertReason(WebhookSignatureVerificationException::REASON_MALFORMED_HEADER, fn () => WebhookSignature::verify(self::BODY, 't=' . self::NOW, self::SECRET));
        self::assertReason(WebhookSignatureVerificationException::REASON_MISSING_SECRET, fn () => WebhookSignature::verify(self::BODY, $valid, ''));
        self::assertReason(WebhookSignatureVerificationException::REASON_MISSING_SECRET, fn () => WebhookSignature::verify(self::BODY, $valid, []));
    }

    public function testParseHeaderReadsEveryV1AndIgnoresUnknownSchemes(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('B', 64);

        self::assertSame(
            ['timestamp' => self::NOW, 'signatures' => [$a, strtolower($b)]],
            WebhookSignature::parseHeader('t=' . self::NOW . ",v1={$a},v0=zzz,v1={$b}"),
        );
    }

    public function testConstructEventReturnsDecodedPayload(): void
    {
        $body = (string) json_encode([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'event' => 'vat.validated',
            'timestamp' => self::NOW,
            'data' => ['object' => ['vat_uid' => 'ATU12345678', 'state' => 'valid']],
        ]);
        $header = WebhookSignature::generateHeader($body, self::SECRET, self::NOW);

        $event = WebhookSignature::constructEvent($body, $header, self::SECRET, now: self::NOW);

        self::assertSame('vat.validated', $event['event']);
        self::assertSame('ATU12345678', $event['data']['object']['vat_uid']);
    }

    public function testConstructEventRejectsSignedNonEvents(): void
    {
        foreach (['not json', '{"foo":1}'] as $body) {
            $header = WebhookSignature::generateHeader($body, self::SECRET, self::NOW);
            self::assertReason(
                WebhookSignatureVerificationException::REASON_INVALID_PAYLOAD,
                fn () => WebhookSignature::constructEvent($body, $header, self::SECRET, now: self::NOW),
            );
        }
    }

    private static function assertReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected WebhookSignatureVerificationException ({$reason})");
        } catch (WebhookSignatureVerificationException $e) {
            self::assertSame($reason, $e->getReason());
        }
    }
}
