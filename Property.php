<?php

namespace Plugins\SiteSEO;

class Property
{
    /** @var string */
    protected $prefix = 'og';

    /** @var string */
    protected $property;

    /** @var string */
    protected $content;

    public function __construct(string $prefix, string $property, string $content)
    {
        $this->prefix = $prefix;
        $this->property = $property;
        $this->content = $content;
    }

    public static function make(string $prefix, string $property, string $content)
    {
        return new static($prefix, $property, $content);
    }

    public function __toString(): string
    {
        // Full entity escaping, not just the quote. Escaping only `"` left `&`
        // to corrupt the value (`&amp;` round-trips wrong) and left `<`/`>` in
        // place, so the value was only ever safe by virtue of the surrounding
        // quotes — a fragile guarantee to rest user content on.
        $content = htmlspecialchars((string) $this->content, ENT_QUOTES, 'UTF-8');

        return "<meta property=\"{$this->prefix}:{$this->property}\" content=\"{$content}\">";
    }
}
