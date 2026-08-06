<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\SiteSEO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\SiteSEO\Infrastructure\Gateways\SearchEngineGateway;

/**
 * Regression cover for SE-01: the engine host came from the request body and
 * was interpolated straight into the outbound URL, so any authenticated caller
 * could make the server POST a JSON body to a host of their choosing.
 */
#[CoversClass(SearchEngineGateway::class)]
final class SearchEngineGatewaySsrfTest extends TestCase
{
    /** Records every URL the gateway attempts, so we can assert none escaped. */
    private array $requested = [];

    private function gateway(): SearchEngineGateway
    {
        $http = $this->createStub(HttpClientPort::class);
        $http->method('post')->willReturnCallback(function (string $url) {
            $this->requested[] = $url;
            throw new \RuntimeException('network disabled in tests');
        });
        $http->method('get')->willReturnCallback(function (string $url) {
            $this->requested[] = $url;
            throw new \RuntimeException('network disabled in tests');
        });

        return new SearchEngineGateway($http);
    }

    /** @return list<array{string}> */
    public static function hostileEngines(): array
    {
        return [
            ['evil.test'],
            ['169.254.169.254'],
            ['localhost'],
            // Suffix and query tricks a naive substring check would let through.
            ['api.indexnow.org.evil.test'],
            ['evil.test?x=api.indexnow.org'],
        ];
    }

    #[DataProvider('hostileEngines')]
    public function test_indexnow_refuses_an_engine_outside_the_allowlist(string $engine): void
    {
        $this->expectException(GatewayException::class);

        try {
            $this->gateway()->submitIndexNow('example.test', [$engine => 'key'], ['https://example.test/a']);
        } finally {
            self::assertSame([], $this->requested, 'no request may be attempted at all');
        }
    }

    #[DataProvider('hostileEngines')]
    public function test_ping_refuses_an_engine_outside_the_allowlist(string $engine): void
    {
        $this->expectException(GatewayException::class);
        $this->gateway()->pingSitemap('https://example.test/sitemap.xml', [$engine]);
    }

    public function test_a_published_indexnow_endpoint_is_attempted(): void
    {
        try {
            $this->gateway()->submitIndexNow('example.test', ['api.indexnow.org' => 'k'], ['https://example.test/a']);
        } catch (GatewayException) {
            // The stub always throws; we only care that the URL was built.
        }

        self::assertSame(['https://api.indexnow.org/indexnow'], $this->requested);
    }

    public function test_the_allowlist_is_extensible_by_configuration(): void
    {
        $_ENV['SEO_ENGINE_ALLOWLIST'] = 'indexnow.example-region.test';

        try {
            $this->gateway()->submitIndexNow(
                'example.test',
                ['indexnow.example-region.test' => 'k'],
                ['https://example.test/a'],
            );
        } catch (GatewayException) {
            // stub throws
        } finally {
            unset($_ENV['SEO_ENGINE_ALLOWLIST']);
        }

        self::assertSame(['https://indexnow.example-region.test/indexnow'], $this->requested);
    }
}
