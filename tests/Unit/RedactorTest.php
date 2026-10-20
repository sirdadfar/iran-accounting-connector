<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testHeaderShapedSecretsAreRedacted(): void
    {
        $text = "GET /invoices HTTP/1.1\r\nAuthorization: Bearer abc.def-123\r\nX-Api-Key: super-secret";

        $redacted = Redactor::secrets($text);

        $this->assertStringNotContainsString('abc.def-123', $redacted);
        $this->assertStringNotContainsString('super-secret', $redacted);
        $this->assertStringContainsString('Authorization: [REDACTED]', $redacted);
        $this->assertStringContainsString('X-Api-Key: [REDACTED]', $redacted);
    }

    public function testJsonAndFormShapedSecretsAreRedacted(): void
    {
        $json = '{"user":"ali","password":"hunter2","token":"t0ken-value"}';
        $form = 'client_secret=s3cr3t&grant_type=password';
        $bare = 'request failed with token=abc123 and password: letmein';

        foreach ([$json, $form, $bare] as $text) {
            $redacted = Redactor::secrets($text);

            $this->assertStringContainsString(Redactor::MASK, $redacted);
            foreach (['hunter2', 't0ken-value', 's3cr3t', 'abc123', 'letmein'] as $secret) {
                $this->assertStringNotContainsString($secret, $redacted);
            }
        }
    }

    public function testBearerAndBasicTokensWithoutHeaderNamesAreRedacted(): void
    {
        $redacted = Redactor::secrets('Retried with Bearer eyJhbGciOiJIUzI1NiJ9.payload and Basic dXNlcjpwYXNz');

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $redacted);
        $this->assertSame('Retried with Bearer [REDACTED] and Basic [REDACTED]', $redacted);
    }

    public function testQueryStringSecretsAreRedacted(): void
    {
        $redacted = Redactor::secrets('https://erp.example.test/callback?api_key=abc123&page=2');

        $this->assertStringNotContainsString('abc123', $redacted);
        $this->assertStringContainsString('page=2', $redacted);
    }

    public function testEmptyTextIsUnchanged(): void
    {
        $this->assertSame('', Redactor::secrets(''));
        $this->assertSame('', Redactor::mask(''));
        $this->assertSame('', Redactor::pii(''));
    }

    public function testMaskKeepsEdgesAndHidesTheMiddle(): void
    {
        $this->assertSame('00******79', Redactor::mask('0012345679'));
        $this->assertSame('0912*****67', Redactor::mask('09121234567', 4, 2));
        $this->assertSame('***', Redactor::mask('123'));
        $this->assertSame('****', Redactor::mask('1234', 2, 2));
    }

    public function testPiiMasksMobilesAndEmailsInFreeText(): void
    {
        $text = 'Call 09121234567 or e-mail ali.reza@example.test; invoice 1234567 stays.';

        $redacted = Redactor::pii($text);

        $this->assertStringNotContainsString('09121234567', $redacted);
        $this->assertStringContainsString('0912****67', $redacted);
        $this->assertStringNotContainsString('ali.reza@example.test', $redacted);
        $this->assertStringContainsString('***@example.test', $redacted);
        $this->assertStringContainsString('invoice 1234567 stays', $redacted);
    }
}
