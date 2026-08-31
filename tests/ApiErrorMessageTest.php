<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Taxora\Sdk\Exceptions\ApiErrorMessage;
use Taxora\Sdk\Exceptions\AuthenticationException;
use Taxora\Sdk\Exceptions\HttpException;

final class ApiErrorMessageTest extends TestCase
{
    public function testJsonMessageIsUsedVerbatim(): void
    {
        self::assertSame(
            'VAT number is unknown to the provider.',
            ApiErrorMessage::describe('{"success":false,"message":"VAT number is unknown to the provider."}', 400)
        );
    }

    public function testJsonErrorKeyIsUsed(): void
    {
        self::assertSame('rate limit reached', ApiErrorMessage::describe('{"error":"rate limit reached"}', 429));
    }

    public function testFirstValidationErrorIsUsed(): void
    {
        self::assertSame(
            'vat_uid: The vat uid field is required.',
            ApiErrorMessage::describe('{"errors":{"vat_uid":["The vat uid field is required."]}}', 400)
        );
    }

    public function testHtmlBodyFallsBackToTheStatusLine(): void
    {
        $html = "  <!DOCTYPE html>\n<html><body><p>Error code: 504</p></body></html>\n";

        self::assertSame('Taxora API request failed (HTTP 504 Gateway Timeout).', ApiErrorMessage::describe($html, 504));
    }

    public function testHtmlInsideAJsonMessageIsRejected(): void
    {
        $body = json_encode(['message' => '<!DOCTYPE html><html><body>nope</body></html>']);

        self::assertSame('Taxora API request failed (HTTP 500 Internal Server Error).', ApiErrorMessage::describe($body, 500));
    }

    public function testEmptyBodyFallsBackToTheStatusLine(): void
    {
        self::assertSame('Taxora API request failed (HTTP 503 Service Unavailable).', ApiErrorMessage::describe('', 503));
        self::assertSame('Taxora API request failed (HTTP 418).', ApiErrorMessage::describe(null, 418));
    }

    public function testShortPlainTextBodyIsKept(): void
    {
        self::assertSame('upstream request timeout', ApiErrorMessage::describe('upstream request timeout', 504));
    }

    public function testLongPlainTextBodyFallsBackToTheStatusLine(): void
    {
        self::assertSame(
            'Taxora API request failed (HTTP 500 Internal Server Error).',
            ApiErrorMessage::describe(str_repeat('a', 201), 500)
        );
    }

    public function testJsonMessageIsCollapsedAndTruncated(): void
    {
        $body = json_encode(['message' => "line one\n\nline " . str_repeat('x', 600)]);

        $message = ApiErrorMessage::describe($body, 400);

        self::assertStringStartsWith('line one line xxx', $message);
        self::assertStringEndsWith('…', $message);
        self::assertSame(500, mb_strlen($message));
    }

    public function testHttpExceptionFromResponseKeepsTheRawBody(): void
    {
        $html = '<html><body>App Platform failed to forward this request</body></html>';

        $exception = HttpException::fromResponse(504, $html);

        self::assertSame('Taxora API request failed (HTTP 504 Gateway Timeout).', $exception->getMessage());
        self::assertSame(504, $exception->getStatusCode());
        self::assertSame($html, $exception->getResponseBody());
    }

    public function testAuthenticationExceptionFactories(): void
    {
        self::assertSame(
            'invalid credentials',
            AuthenticationException::fromResponse('{"message":"invalid credentials"}')->getMessage()
        );

        self::assertSame(
            'Unauthorized and refresh failed: Taxora API request failed (HTTP 401 Unauthorized).',
            AuthenticationException::refreshFailed('<html><body>401</body></html>')->getMessage()
        );
    }
}
