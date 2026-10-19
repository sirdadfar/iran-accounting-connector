<?php

declare(strict_types=1);

namespace IranAccounting\Http;

/**
 * PSR-18 style synchronous HTTP abstraction. The library does not depend on a
 * concrete PSR-18 package, so integrators can bridge their existing client in
 * a few lines; CurlClient is the bundled default.
 */
interface Client
{
    public function send(Request $request): Response;
}
