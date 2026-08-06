<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\SiteSEO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\SiteSEO\Sitemap\SitemapBuilder;

/**
 * Regression cover for SE-02: option keys became element NAMES and values were
 * passed to addChild() unescaped, which does not encode ampersands — so one URL
 * with a query string produced malformed XML and invalidated the whole sitemap.
 */
#[CoversClass(SitemapBuilder::class)]
final class SitemapEscapingTest extends TestCase
{
    private function builder(): SitemapBuilder
    {
        return new SitemapBuilder('https://example.test', ['images' => true]);
    }

    /** Render the document to a string via the builder's own save path. */
    private function xml(SitemapBuilder $builder): string
    {
        $file = $builder->saveTemp();
        $xml  = (string) file_get_contents($file);
        @unlink($file);

        return $xml;
    }

    public function test_an_ampersand_in_a_url_produces_valid_xml(): void
    {
        $builder = $this->builder();
        $builder->url('https://example.test/search?a=1&b=2&c=3')->append();
        $xml = $this->xml($builder);

        self::assertStringNotContainsString('?a=1&b=2', $xml, 'a bare & is not valid XML');
        self::assertStringContainsString('&amp;', $xml);

        // The real check: it must actually parse.
        self::assertNotFalse(
            simplexml_load_string($xml),
            'a single unescaped ampersand invalidates the entire sitemap',
        );
    }

    public function test_image_options_outside_the_schema_are_dropped(): void
    {
        $builder = $this->builder();
        $builder->url('https://example.test/p')
            ->image('https://example.test/i.jpg', [
                'caption'      => 'A & B',
                'not_a_schema' => 'ignored',
            ])
            ->append();
        $xml = $this->xml($builder);

        self::assertStringContainsString('image:caption', $xml);
        self::assertStringNotContainsString('not_a_schema', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function test_a_hostile_option_key_cannot_corrupt_the_document(): void
    {
        // A key that is not a valid XML name previously threw or produced a
        // broken document, taking every other URL with it.
        $builder = $this->builder();
        $builder->url('https://example.test/p')
            ->image('https://example.test/i.jpg', ['<bad name>' => 'x', 'caption' => 'ok'])
            ->append();
        $xml = $this->xml($builder);

        self::assertNotFalse(simplexml_load_string($xml));
        self::assertStringContainsString('ok', $xml);
    }

    public function test_markup_in_a_caption_is_escaped(): void
    {
        $builder = $this->builder();
        $builder->url('https://example.test/p')
            ->image('https://example.test/i.jpg', ['caption' => '<script>alert(1)</script>'])
            ->append();
        $xml = $this->xml($builder);

        self::assertStringNotContainsString('<script>', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }
}
