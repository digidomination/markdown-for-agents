<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * A token estimate for the X-Markdown-Tokens and X-Original-Tokens headers:
 * characters divided by four, the usual rule of thumb for English text with
 * current tokenizers. It is an estimate, not a count: German runs a little
 * higher, code and markup higher still.
 */
final class Tokens
{
    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text, 'UTF-8') / 4);
    }
}
