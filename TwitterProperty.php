<?php

namespace Plugins\SiteSEO;

class TwitterProperty extends Property
{
    /** @var string */
    protected $prefix = 'twitter';

    public function __toString(): string
    {
        // See Property::__toString() — escape the whole value, not just the quote.
        $content = htmlspecialchars((string) $this->content, ENT_QUOTES, 'UTF-8');

        return "<meta name=\"{$this->prefix}:{$this->property}\" content=\"{$content}\">";
    }
}
