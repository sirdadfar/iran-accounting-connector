<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpRequestTest extends TestCase
{
    public function testValidRequestIsAccepted(): void
    {
        $request = new Request('GET', 'https://erp.example.test/api/v1/invoices', ['Accept' => 'application/json']);

        $this->assertSame('GET', $request->method);
        $this->assertSame('application/json', $request->header('accept'));
        $this->assertTrue($request->hasHeader('ACCEPT'));
        $this->assertNull($request->header('X-Missing'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['GE T'];
        yield 'crlf' => ["GET\r\nX-Injected: 1"];
        yield 'punctuation' => ['GET/'];
    }

    #[DataProvider('invalidMethods')]
    public function testInvalidMethodsAreRejected(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request($method, 'https://erp.example.test/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'relative' => ['/invoices'];
        yield 'scheme-relative' => ['//erp.example.test/invoices'];
        yield 'ftp' => ['ftp://erp.example.test/invoices'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'credentials' => ['https://user:pass@erp.example.test/invoices'];
        yield 'no-host' => ['https:///invoices'];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', $url);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'nan' => [NAN];
    }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutsAreRejected(float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', 'https://erp.example.test/', [], null, $timeout);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'space in name' => [['Bad Name' => 'x']];
        yield 'crlf in name' => [["X-Bad\r\n" => 'x']];
        yield 'empty name' => [['' => 'x']];
        yield 'cr in value' => [['X-Test' => "a\rb"]];
        yield 'lf in value' => [['X-Test' => "a\nb"]];
        yield 'nul in value' => [['X-Test' => "a\0b"]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalidHeaders')]
    public function testHeaderInjectionIsRejected(array $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Request('GET', 'https://erp.example.test/', $headers);
    }

    public function testJsonFactorySetsDefaultsWithoutOverridingCallerValues(): void
    {
        $request = Request::json(
            'post',
            'https://erp.example.test/invoices',
            ['number' => 'INV-1'],
            ['content-type' => 'application/vnd.api+json'],
        );

        $this->assertSame('POST', $request->method);
        $this->assertSame('application/vnd.api+json', $request->header('Content-Type'));
        $this->assertSame('application/json', $request->header('Accept'));
        $this->assertSame('{"number":"INV-1"}', $request->body);
    }
}
