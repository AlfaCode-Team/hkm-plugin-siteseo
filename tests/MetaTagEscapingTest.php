<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\SiteSEO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\SiteSEO\Channel;
use Plugins\SiteSEO\OpenGraph;
use Plugins\SiteSEO\Property;
use Plugins\SiteSEO\Schema\Thing;
use Plugins\SiteSEO\TwitterProperty;
use Plugins\SiteSEO\Type;

/**
 * Regression cover for the stored-XSS in the rendered <head>.
 *
 * Titles and descriptions on a real site are user-authored — a product name, an
 * uploaded document's title, a post headline. Type::__toString() interpolated
 * them into the "Primary Meta Tags" block with NO escaping at all, so a title
 * containing `</title><script>` closed the element and everything after it was
 * parsed as markup. The og:/twitter: tags escaped only `"`, which happened to
 * hold inside a quoted attribute but left `&` corrupting values.
 *
 * The JSON-LD emitters had the same shape of bug: json_encode() without
 * JSON_HEX_TAG leaves `<` intact, so a `</script>` in any node closes the
 * script element early — and in Channel that was compounded by
 * JSON_UNESCAPED_SLASHES keeping the closing sequence whole.
 *
 * These tests assert on PARSED output wherever possible rather than on the
 * escaped spelling: what matters is that a payload arrives as inert text, not
 * which entity form it took.
 */
#[CoversClass(Type::class)]
#[CoversClass(Property::class)]
#[CoversClass(TwitterProperty::class)]
#[CoversClass(Thing::class)]
#[CoversClass(Channel::class)]
final class MetaTagEscapingTest extends TestCase
{
    /** Breaks out of both element text and a double-quoted attribute. */
    private const PAYLOAD = 'Physics P1" onload=alert(1)></title><script>alert(document.cookie)</script>';

    /** Parse a <head> fragment and hand back the document. */
    private function head(string $fragment): \DOMDocument
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML("<html><head>{$fragment}</head><body></body></html>");
        libxml_clear_errors();

        return $doc;
    }

    public function test_a_hostile_title_injects_no_elements(): void
    {
        $doc = $this->head((string) OpenGraph::article(self::PAYLOAD));

        self::assertSame(0, $doc->getElementsByTagName('script')->length, 'the payload opened a <script>');
        self::assertSame(0, $doc->getElementsByTagName('svg')->length);
    }

    public function test_a_hostile_title_round_trips_as_inert_text(): void
    {
        $doc   = $this->head((string) OpenGraph::article(self::PAYLOAD));
        $title = $doc->getElementsByTagName('title')->item(0);

        self::assertNotNull($title);
        // Byte-identical after parsing ⇒ it was data the whole way through.
        self::assertSame(self::PAYLOAD, $title->textContent);
    }

    public function test_a_hostile_description_stays_inside_its_attribute(): void
    {
        $og = OpenGraph::article('Safe title');
        $og->description('Notes </script><svg onload=alert(2)>');

        $doc = $this->head((string) $og);
        self::assertSame(0, $doc->getElementsByTagName('svg')->length);

        $metas = [];
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $metas[$meta->getAttribute('name') ?: $meta->getAttribute('property')] = $meta->getAttribute('content');
        }
        self::assertSame('Notes </script><svg onload=alert(2)>', $metas['description'] ?? null);
        self::assertSame('Notes </script><svg onload=alert(2)>', $metas['og:description'] ?? null);
    }

    /**
     * Escaping only `"` left `&` alone, so `Tom & Jerry` round-tripped as
     * `Tom &amp; Jerry` only by accident of the parser being lenient — and any
     * value containing a real entity was corrupted.
     */
    public function test_property_tags_escape_ampersands_not_just_quotes(): void
    {
        $property = new Property('og', 'title', 'Tom & Jerry "quoted"');
        $twitter  = new TwitterProperty('twitter', 'title', 'Tom & Jerry "quoted"');

        foreach ([(string) $property, (string) $twitter] as $rendered) {
            $doc  = $this->head($rendered);
            $meta = $doc->getElementsByTagName('meta')->item(0);

            self::assertNotNull($meta);
            self::assertSame('Tom & Jerry "quoted"', $meta->getAttribute('content'));
        }
    }

    public function test_a_json_ld_node_cannot_close_its_own_script_element(): void
    {
        $thing = new Thing('Article', ['headline' => 'End</script><img src=x onerror=alert(1)>']);

        $rendered = (string) $thing;

        self::assertStringNotContainsString('</script><img', $rendered);
        self::assertSame(1, substr_count($rendered, '</script>'), 'exactly one closing tag — its own');
        self::assertSame(0, $this->head($rendered)->getElementsByTagName('img')->length);
    }

    public function test_the_bootstrap_script_cannot_be_closed_by_shared_data(): void
    {
        Channel::share('bio', 'End</script><img src=x onerror=alert(1)>');

        $rendered = Channel::renderSharedScript();

        self::assertStringNotContainsString('</script><img', $rendered);
        self::assertSame(1, substr_count($rendered, '</script>'));
        self::assertSame(0, $this->head($rendered)->getElementsByTagName('img')->length);
    }
}
