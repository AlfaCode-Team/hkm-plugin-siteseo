<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\SiteSEO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\SiteSEO\Application\Services\SeoService;
use Plugins\SiteSEO\Infrastructure\Gateways\SearchEngineGateway;
use Plugins\SiteSEO\RobotsTxtEditor;

/**
 * Regression cover for GET /robots.txt answering 500: the editor was a
 * WordPress port, and its constructor read ABSPATH ("Undefined constant
 * Plugins\SiteSEO\ABSPATH") before anything else could run.
 */
#[CoversClass(RobotsTxtEditor::class)]
final class RobotsTxtEditorTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
        }
    }

    private function tempFile(string $contents): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'robots');
        file_put_contents($this->file, $contents);

        return $this->file;
    }

    /** What SeoController@robots serves: constructing it must not fail, and the body is never empty. */
    public function test_the_service_editor_serves_an_allow_all_default(): void
    {
        $service = new SeoService((new \ReflectionClass(SearchEngineGateway::class))->newInstanceWithoutConstructor());

        self::assertSame("User-agent: *\nAllow: /\n", $service->robots()->getContent());
    }

    public function test_an_existing_file_is_served_as_it_is(): void
    {
        $path = $this->tempFile("User-agent: *\nDisallow: /private\n");

        self::assertSame("User-agent: *\nDisallow: /private\n", (new RobotsTxtEditor(filePath: $path))->getContent());
    }

    public function test_a_missing_file_falls_back_to_the_default_and_is_not_created(): void
    {
        $path = sys_get_temp_dir() . '/robots-missing-' . bin2hex(random_bytes(4)) . '.txt';

        self::assertSame(RobotsTxtEditor::DEFAULT_CONTENT, (new RobotsTxtEditor(filePath: $path))->getContent());
        self::assertFileDoesNotExist($path);
    }

    public function test_rules_are_parsed_per_user_agent(): void
    {
        $path = $this->tempFile("# comment\nUser-agent: *\nDisallow: /a\nAllow: /b\nSitemap: https://x.test/sitemap.xml\n\nUser-agent: Googlebot\nDisallow: /c\n");
        $editor = new RobotsTxtEditor(filePath: $path);
        $rules = $editor->readRobotsTxt();

        self::assertSame(['/a'], $rules['*']['Disallow']);
        self::assertSame(['/b'], $rules['*']['Allow']);
        self::assertSame(['/c'], $rules['Googlebot']['Disallow']);
        self::assertSame(['https://x.test/sitemap.xml'], $editor->getSitemaps());
    }

    /** Disallowing a path takes it out of Allow, and the result is written back. */
    public function test_an_update_merges_and_writes_the_file(): void
    {
        $path = $this->tempFile("User-agent: *\nAllow: /a\nAllow: /b\n");
        $editor = new RobotsTxtEditor(filePath: $path);

        $editor->updateRobotsTxt(['*' => ['Disallow' => ['/a']]]);

        self::assertSame("User-agent: *\nAllow: /b\nDisallow: /a\n", file_get_contents($path));
        self::assertSame(file_get_contents($path), $editor->getContent());
    }

    public function test_without_a_path_an_update_writes_nothing(): void
    {
        $editor = new RobotsTxtEditor();
        $editor->updateRobotsTxt(['*' => ['Disallow' => ['/x']]]);

        self::assertSame("User-agent: *\nDisallow: /x\n", $editor->getContent());
    }
}
