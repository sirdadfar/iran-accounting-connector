<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Http\CurlClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurlClientTest extends TestCase
{
    public function testInvalidConnectTimeoutIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurlClient(0);
    }

    public function testUnreadableCaBundleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurlClient(5_000, '/nonexistent/ca-bundle.pem');
    }

    public function testDirectoryIsNotAcceptedAsCaBundle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurlClient(5_000, __DIR__);
    }

    public function testDefaultConstructionIsValid(): void
    {
        $client = new CurlClient();

        $this->assertInstanceOf(CurlClient::class, $client);
    }
}
