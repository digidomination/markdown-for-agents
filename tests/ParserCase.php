<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use Digidomination\MarkdownForAgents\Html;
use Digidomination\MarkdownForAgents\PageConverter;
use PHPUnit\Framework\TestCase;

/** Runs conversions under every parser this PHP has, so both code paths stay equal. */
abstract class ParserCase extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function parsers(): iterable
    {
        yield 'libxml' => [Html::PARSER_LIBXML];
        if (class_exists(\Dom\HTMLDocument::class)) {
            yield 'html5' => [Html::PARSER_HTML5];
        }
    }

    /** Markdown for a <main> fragment, without frontmatter. */
    protected static function md(string $parser, string $body, string $lang = 'en', array $options = []): string
    {
        $html = '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="utf-8"><title>T</title>'
            . '<link rel="canonical" href="https://example.com/dir/page"></head><body>'
            . '<header><nav><a href="/">Home</a></nav></header><main>' . $body . '</main>'
            . '<footer>Footer text</footer></body></html>';

        return rtrim((new PageConverter(['frontmatter' => false, 'parser' => $parser] + $options))
            ->convert($html, 'https://example.com/dir/page')['markdown'], "\n");
    }
}
