<?php

namespace Plugins\SiteSEO;


/**
 * RobotsTxtEditor: A class for parsing, updating, and writing robots.txt files.
 *
 * This class provides methods to read, parse, update, and write robots.txt files,
 * allowing for fine-grained control over crawling rules for search engine bots.
 *
 * Plain PHP: it was ported from a WordPress codebase and still called ABSPATH,
 * get_option(), apply_filters() and friends, so constructing it was a fatal
 * error ("Undefined constant Plugins\SiteSEO\ABSPATH") and GET /robots.txt
 * answered 500. Now:
 *
 *   - the constructor does no I/O; a file is only touched when a path is given;
 *   - with no path, or a missing/empty file, getContent() is an allow-all
 *     default rather than an empty body;
 *   - updateRobotsTxt() writes the file only when there is a path to write.
 *
 * @author Hakeem Shamavu (hakimushamavu@gmail.com)
 * @link https://developers.google.com/webmasters/control-crawl-index/docs/robots_txt
 * @link https://help.yandex.com/webmaster/controlling-robot/robots-txt.xml
 */

class RobotsTxtEditor
{
    // default encoding
    const DEFAULT_ENCODING = 'UTF-8';

    // directives
    const DIRECTIVE_NOINDEX = 'Noindex';
    const DIRECTIVE_ALLOW = 'Allow';
    const DIRECTIVE_DISALLOW = 'Disallow';
    const DIRECTIVE_HOST = 'Host';
    const DIRECTIVE_SITEMAP = 'Sitemap';
    const DIRECTIVE_USERAGENT = 'User-agent';
    const DIRECTIVE_CRAWL_DELAY = 'Crawl-delay';
    const DIRECTIVE_CLEAN_PARAM = 'Clean-param';

    //default user-agent
    const USER_AGENT_ALL = '*';
    const CRAWL_DELAY_SECONDS = 10;

    /** What is served when there is no robots.txt to read: crawl everything. */
    const DEFAULT_CONTENT = "User-agent: *\nAllow: /\n";

    protected ?string $filePath;
    protected $encoding;

    /**
     * @var string $content Original robots.txt content
     */
    protected $contents = '';

    /**
     * @var array $rules Rules with all parsed directives by all user-agents
     */
    protected $rules = [];

    protected $reading = false;


    /**
     * @param string      $encoding The character encoding of the robots.txt file (default is UTF-8).
     * @param string|null $filePath The robots.txt to read and write; null for none (the default
     *                              content is served, and nothing is ever written).
     */
    public function __construct($encoding = self::DEFAULT_ENCODING, ?string $filePath = null)
    {
        $this->encoding = $encoding;
        $this->filePath = $filePath;
    }

    /**
     * Get rules by specific bot (user-agent)
     * Use $userAgent = NULL to get all rules for all user-agents grouped by user-agent.
     * Use $userAgent = '*' to get common rules.
     * Use $userAgent = 'YandexBot' to get rules for user-agent 'YandexBot'.
     *
     * @param string $userAgent
     * @return array
     */
    public function getRules($userAgent = null)
    {
        if (is_null($userAgent)) {
            return $this->rules;
        }

        return $this->rules[$userAgent] ?? [];
    }

    /**
     * Get sitemaps links.
     * Sitemap always relates to all user-agents and return in rules with user-agent "*"
     *
     * @return array
     */
    public function getSitemaps()
    {
        $rules = $this->getRules(self::USER_AGENT_ALL);

        return !empty($rules[self::DIRECTIVE_SITEMAP]) ? $rules[self::DIRECTIVE_SITEMAP] : [];
    }

    /**
     * The robots.txt content: what was written or read, else the file's, else
     * {@see DEFAULT_CONTENT}. Never empty, so it can be served as-is.
     *
     * @return string
     */
    public function getContent()
    {
        if ($this->contents === '') {
            $this->contents = $this->readFile();
        }

        return $this->contents !== '' ? $this->contents : self::DEFAULT_CONTENT;
    }

    /**
     * Read and parse the contents of the robots.txt file.
     *
     * @return array An associative array representing the parsed robots.txt file.
     */
    public function readRobotsTxt()
    {
        $this->reading = true;
        if ($this->contents === '') {
            $this->contents = $this->readFile();
        }

        if ($this->contents === '') return [];

        $parsedRobotsTxt = [];
        $currentUserAgent = null;

        foreach (explode("\n", $this->contents) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (stripos($line, self::DIRECTIVE_USERAGENT . ':') === 0) {
                $currentUserAgent = trim(substr($line, strlen(self::DIRECTIVE_USERAGENT . ':')));
                $parsedRobotsTxt[$currentUserAgent] ??= [];
                continue;
            }

            $directive = $this->getDerictiveOnLine($line);
            // A directive before any User-agent line belongs to no group.
            if ($directive && $currentUserAgent !== null) {
                $parsedRobotsTxt[$currentUserAgent][$directive][] = trim(substr($line, strlen($directive . ':')));
            }
        }

        $this->rules = $parsedRobotsTxt;
        return $this->rules;
    }

    /**
     * Return array of supported directives
     *
     * @return array
     */
    protected function getAllowedDirectives()
    {
        return [
            self::DIRECTIVE_NOINDEX,
            self::DIRECTIVE_ALLOW,
            self::DIRECTIVE_DISALLOW,
            self::DIRECTIVE_HOST,
            self::DIRECTIVE_SITEMAP,
            self::DIRECTIVE_USERAGENT,
            self::DIRECTIVE_CRAWL_DELAY,
            self::DIRECTIVE_CLEAN_PARAM,
        ];
    }

    protected function getDerictiveOnLine(string $line): string|false
    {
        foreach ($this->getAllowedDirectives() as $directive) {
            if (stripos($line, $directive . ':') === 0) {
                return $directive;
            }
        }

        return false;
    }

    /**
     * Merge new rules into the current ones and rebuild the content; written
     * to the file only when this editor has a path.
     *
     * Disallowing a path removes it from Allow for that user-agent, and
     * allowing one removes it from Disallow, so a path is never in both.
     *
     * @param array $rules user-agent => [directive => value(s)]
     */
    public function updateRobotsTxt($rules = [])
    {
        if (!$this->reading) {
            $this->readRobotsTxt();
        }

        foreach ($rules as $user_agent => $value) {
            $directives = (array) ($this->rules[$user_agent] ?? []);

            if (isset($value[self::DIRECTIVE_DISALLOW])) {
                $disallow = (array) $value[self::DIRECTIVE_DISALLOW];
                $directives[self::DIRECTIVE_ALLOW] = array_values(array_diff($directives[self::DIRECTIVE_ALLOW] ?? [], $disallow));
                $directives[self::DIRECTIVE_DISALLOW] = self::mergeUnique($directives[self::DIRECTIVE_DISALLOW] ?? [], $disallow);
            }

            if (isset($value[self::DIRECTIVE_ALLOW])) {
                $allow = (array) $value[self::DIRECTIVE_ALLOW];
                $directives[self::DIRECTIVE_DISALLOW] = array_values(array_diff($directives[self::DIRECTIVE_DISALLOW] ?? [], $allow));
                $directives[self::DIRECTIVE_ALLOW] = self::mergeUnique($directives[self::DIRECTIVE_ALLOW] ?? [], $allow);
            }

            foreach ([self::DIRECTIVE_HOST, self::DIRECTIVE_SITEMAP, self::DIRECTIVE_CRAWL_DELAY, self::DIRECTIVE_CLEAN_PARAM] as $directive) {
                if (isset($value[$directive])) {
                    $directives[$directive] = self::mergeUnique($directives[$directive] ?? [], (array) $value[$directive]);
                }
            }

            $this->rules[$user_agent] = $directives;
        }

        $allowedDirectives = array_flip($this->getAllowedDirectives());
        $newContent = '';

        foreach ($this->rules as $userAgent => $directives) {
            $newContent .= ($newContent === '' ? '' : "\n") . self::DIRECTIVE_USERAGENT . ": $userAgent\n";

            foreach (array_intersect_key($directives, $allowedDirectives) as $directive => $values) {
                foreach ((array) $values as $v) {
                    $newContent .= "$directive: $v\n";
                }
            }
        }

        $this->contents = $newContent;

        if ($this->filePath !== null) {
            file_put_contents($this->filePath, $newContent);
        }
    }

    /**
     * Add rules for a specific user agent to the robots.txt file.
     *
     * @param string $userAgent The user agent for which to add rules.
     * @param array $rules An associative array of rules to add for the specified user agent.
     */
    public function addRulesForUserAgent($userAgent, $rules = [])
    {
        $delay = $userAgent == self::USER_AGENT_ALL ? self::CRAWL_DELAY_SECONDS : 5;

        $rules = empty($rules)
            ? [self::DIRECTIVE_DISALLOW => [], self::DIRECTIVE_CRAWL_DELAY => $delay]
            : [...$rules, self::DIRECTIVE_CRAWL_DELAY => $delay];

        $this->updateRobotsTxt([$userAgent => $rules]);
    }

    /** The file's content, or '' when there is no path or nothing readable at it. */
    private function readFile(): string
    {
        if ($this->filePath === null || !is_file($this->filePath) || !is_readable($this->filePath)) {
            return '';
        }

        return (string) file_get_contents($this->filePath);
    }

    /** @return list<string> */
    private static function mergeUnique(array $a, array $b): array
    {
        return array_values(array_unique(array_map('strval', [...$a, ...$b])));
    }
}
