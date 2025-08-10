<?php

declare (strict_types = 1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use RediSync\Utils\KeyGenerator;

final class KeyGeneratorTest extends TestCase
{
    public function testIgnoredParamsAreExcludedFromKey(): void
    {
        $psr17 = new Psr17Factory();
        $req   = $psr17->createServerRequest('GET', 'https://e.com/items?nonce=123&lang=tr&id=5');
        $kg    = new KeyGenerator('http:test', ignoredParams: ['nonce']);

        $k1 = $kg->fromRequest($req);

        $req2 = $psr17->createServerRequest('GET', 'https://e.com/items?nonce=456&lang=tr&id=5');
        $k2   = $kg->fromRequest($req2);

        $this->assertSame($k1, $k2, 'ignored param should not affect the key');
    }
}
