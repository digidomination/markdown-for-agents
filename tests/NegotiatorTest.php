<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use Digidomination\MarkdownForAgents\Negotiator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NegotiatorTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function acceptHeaders(): iterable
    {
        yield 'no header' => [null, false];
        yield 'empty' => ['', false];
        yield 'browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,*/*;q=0.8', false];
        yield 'wildcard only' => ['*/*', false];
        yield 'markdown only' => ['text/markdown', true];
        yield 'markdown first' => ['text/markdown, text/html', true];
        yield 'equal rank' => ['text/html, text/markdown', true];
        yield 'agent style' => ['text/markdown, text/html, */*', true];
        yield 'markdown preferred' => ['text/markdown, text/html;q=0.9', true];
        yield 'html preferred' => ['text/html, text/markdown;q=0.9', false];
        yield 'markdown lower' => ['text/markdown;q=0.5, text/html', false];
        yield 'markdown refused' => ['text/markdown;q=0', false];
        yield 'text wildcard wins' => ['text/*, text/markdown;q=0.5', false];
        yield 'text wildcard lower' => ['text/*;q=0.5, text/markdown', true];
        yield 'case and spaces' => [' TEXT/Markdown ; charset=utf-8 ', true];
        yield 'x-markdown' => ['text/x-markdown', true];
        yield 'exact html beats wildcard' => ['text/markdown;q=0.8, */*, text/html;q=0.5', true];
    }

    #[DataProvider('acceptHeaders')]
    public function testPrefersMarkdown(?string $accept, bool $expected): void
    {
        self::assertSame($expected, Negotiator::prefersMarkdown($accept));
    }

    /** @return iterable<array{string, string}> */
    public static function pathPairs(): iterable
    {
        yield ['/', '/index.html.md'];
        yield ['/pricing', '/pricing.md'];
        yield ['/docs/', '/docs/index.html.md'];
        yield ['/en/blog/some-post', '/en/blog/some-post.md'];
        yield ['/page.html', '/page.html.md'];
    }

    #[DataProvider('pathPairs')]
    public function testPathsMapBothWays(string $html, string $markdown): void
    {
        self::assertSame($markdown, Negotiator::markdownPath($html));
        self::assertSame($html, Negotiator::htmlPath($markdown));
    }

    public function testIndexMdIsAnAlias(): void
    {
        self::assertSame('/', Negotiator::htmlPath('/index.md'));
        self::assertSame('/en/', Negotiator::htmlPath('/en/index.md'));
    }

    public function testNonMarkdownPaths(): void
    {
        self::assertNull(Negotiator::htmlPath('/pricing'));
        self::assertNull(Negotiator::htmlPath('/'));
        self::assertNull(Negotiator::htmlPath('/.md'));
        self::assertNull(Negotiator::htmlPath('/docs/.md'));
        self::assertNull(Negotiator::htmlPath('/readme.mdx'));
    }
}
