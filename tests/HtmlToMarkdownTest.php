<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class HtmlToMarkdownTest extends ParserCase
{
    #[DataProvider('parsers')]
    public function testHeadingsParagraphsAndInline(string $parser): void
    {
        self::assertSame(
            "# Title\n\nSome **bold** and *italic* text with `code` and ~~old~~ new.\n\n## Sub",
            self::md($parser, "<h1>Title</h1>\n<p>Some <strong>bold</strong> and <em>italic</em>\n  text with <code>code</code> and <del>old</del> new.</p><h2>Sub</h2>"),
        );
    }

    #[DataProvider('parsers')]
    public function testLinksResolveAgainstThePage(string $parser): void
    {
        self::assertSame(
            '[root](https://example.com/about) [rel](https://example.com/dir/other) [up](https://example.com/x) '
            . '[frag](https://example.com/dir/page#faq) [ext](https://other.example/a%20b) <https://example.com/> <info@example.com> [call](tel:+4930123)',
            self::md($parser, '<p><a href="/about">root</a> <a href="other">rel</a> <a href="../x">up</a> <a href="#faq">frag</a> '
                . '<a href="https://other.example/a b">ext</a> <a href="https://example.com/">https://example.com/</a> '
                . '<a href="mailto:info@example.com">info@example.com</a> <a href="tel:+4930123">call</a></p>'),
        );
    }

    #[DataProvider('parsers')]
    public function testLinksWithoutTargetOrText(string $parser): void
    {
        self::assertSame(
            'plain [Label](https://example.com/x)',
            self::md($parser, '<p><a>plain</a> <a href="javascript:void(0)"></a><a href="/x" aria-label="Label"><svg></svg></a></p>'),
        );
    }

    #[DataProvider('parsers')]
    public function testImagesNeedAltText(string $parser): void
    {
        self::assertSame(
            "![A cat](https://example.com/img/cat.jpg)\n![Lazy](https://example.com/lazy.jpg)",
            self::md($parser, '<p><img src="/img/cat.jpg" alt="A cat"> <img src="/deco.png" alt=""> '
                . '<img src="data:image/png;base64,AAAA" data-src="/lazy.jpg" alt="Lazy"></p>'),
        );
        self::assertSame('', self::md($parser, '<p><img src="/a.jpg" alt="A"></p>', 'en', ['images' => 'none']));
        self::assertSame(
            '![](https://example.com/deco.png)',
            self::md($parser, '<picture><source srcset="/x.avif"><img src="/deco.png" alt=""></picture>', 'en', ['images' => 'all']),
        );
    }

    #[DataProvider('parsers')]
    public function testLists(string $parser): void
    {
        self::assertSame(
            "- one\n- two\n  - nested\n    1. deep\n- three",
            self::md($parser, '<ul><li>one</li><li>two<ul><li>nested<ol><li>deep</li></ol></li></ul></li><li><p>three</p></li></ul>'),
        );
        self::assertSame("3. c\n4. d", self::md($parser, '<ol start="3"><li>c</li><li>d</li></ol>'));
    }

    #[DataProvider('parsers')]
    public function testListItemWithParagraphs(string $parser): void
    {
        self::assertSame(
            "1. First para\n\n   Second para",
            self::md($parser, '<ol><li><p>First para</p><p>Second para</p></li></ol>'),
        );
    }

    #[DataProvider('parsers')]
    public function testBlockquoteAndRule(string $parser): void
    {
        self::assertSame(
            "> Quote one\n>\n> Quote two\n\n---\n\nAfter",
            self::md($parser, '<blockquote><p>Quote one</p><p>Quote two</p></blockquote><hr><p>After</p>'),
        );
    }

    #[DataProvider('parsers')]
    public function testCodeBlocksKeepTheirText(string $parser): void
    {
        self::assertSame(
            "````json\n{\"a\": \"```\"}\n  <b>kept</b>\n````",
            self::md($parser, '<pre><code class="language-json">{"a": "```"}' . "\n  &lt;b&gt;kept&lt;/b&gt;\n</code></pre>"),
        );
        self::assertSame('Use ``a`b`` here', self::md($parser, '<p>Use <code>a`b</code> here</p>'));
    }

    #[DataProvider('parsers')]
    public function testTables(string $parser): void
    {
        self::assertSame(
            "**Prices**\n\n| Plan | Price |\n| --- | --- |\n| Basic | 29 € |\n| Pro \\| Plus | 59 € <br> per month |",
            self::md($parser, '<table><caption>Prices</caption><thead><tr><th>Plan</th><th>Price</th></tr></thead><tbody>'
                . '<tr><td>Basic</td><td>29&nbsp;€</td></tr><tr><td>Pro | Plus</td><td><p>59 €</p><p>per month</p></td></tr></tbody></table>'),
        );
        // No header row: the first row becomes one; colspan keeps the grid.
        self::assertSame(
            "| a | b |\n| --- | --- |\n| wide |  |",
            self::md($parser, '<table><tr><td>a</td><td>b</td></tr><tr><td colspan="2">wide</td></tr></table>'),
        );
    }

    #[DataProvider('parsers')]
    public function testDefinitionListsAndDetails(string $parser): void
    {
        self::assertSame(
            "**Since**\n2008\n\n**Orders**\n7000+",
            self::md($parser, '<dl><div><dt>Since</dt><dd>2008</dd></div><div><dt>Orders</dt><dd>7000+</dd></div></dl>'),
        );
        self::assertSame(
            "**What does it cost?**\n\nFrom 29 € a month.\n\n### Heading question\n\nAnswer",
            self::md($parser, '<details><summary>What does it cost?</summary><p>From 29 € a month.</p></details>'
                . '<details><summary><h3>Heading question</h3></summary>Answer</details>'),
        );
    }

    #[DataProvider('parsers')]
    public function testCardLinksAttachToTheHeading(string $parser): void
    {
        self::assertSame(
            "Eyebrow\n\n### [Card title](https://example.com/card)\n\nCard text",
            self::md($parser, '<a href="/card" class="card"><span class="eyebrow">Eyebrow</span><h3>Card title</h3><p>Card text</p></a>'),
        );
        self::assertSame(
            '[Only text in a card](https://example.com/c2)',
            self::md($parser, '<a href="/c2"><div><img src="/i.jpg" alt=""></div><div>Only text in a card</div></a>'),
        );
    }

    #[DataProvider('parsers')]
    public function testLabelledImagesAndTabs(string $parser): void
    {
        self::assertSame(
            "Rating: 5 of 5 stars\n\n- Monthly\n- Yearly -15 %",
            self::md($parser, '<p>Rating: <span role="img" aria-label="5 of 5 stars"><svg aria-hidden="true"></svg></span></p>'
                . '<div role="tablist"><button role="tab">Monthly</button><button role="tab">Yearly -15 %</button></div>'),
        );
    }

    #[DataProvider('parsers')]
    public function testHardBreaks(string $parser): void
    {
        self::assertSame("Street 1  \n12345 Town", self::md($parser, '<address>Street 1<br>12345 Town<br></address>'));
    }

    #[DataProvider('parsers')]
    public function testEscaping(string $parser): void
    {
        self::assertSame(
            "1\\. April 2026\n\n\\# not a heading\n\n\\- not a list\n\nCost: 5\\*, \\_under\\_ snake_case, \\[x\\] \\<b> a \\~ b",
            self::md($parser, '<p>1. April 2026</p><p># not a heading</p><p>- not a list</p>'
                . '<p>Cost: 5*, _under_ snake_case, [x] &lt;b&gt; a ~ b</p>'),
        );
    }

    #[DataProvider('parsers')]
    public function testHeadingsAreNotEscapedOrDoubleBold(string $parser): void
    {
        self::assertSame("### 1. Step one\n\n## Bold heading", self::md($parser, '<h3>1. Step one</h3><h2><strong>Bold heading</strong></h2>'));
    }

    #[DataProvider('parsers')]
    public function testQuotesFollowThePageLanguage(string $parser): void
    {
        self::assertSame('Er sagte „Hallo“.', self::md($parser, '<p>Er sagte <q>Hallo</q>.</p>', 'de'));
        self::assertSame('She said “hi”.', self::md($parser, '<p>She said <q>hi</q>.</p>', 'en'));
    }

    #[DataProvider('parsers')]
    public function testWhitespaceAndInvisibleCharacters(string $parser): void
    {
        self::assertSame(
            'Fotoshooting for 29 € now',
            self::md($parser, "<p>  Foto&shy;shooting\n\t for 29&nbsp;€&#8203; now  </p>"),
        );
    }

    #[DataProvider('parsers')]
    public function testGalleriesGoOneImagePerLine(string $parser): void
    {
        self::assertSame(
            "![One](https://example.com/1.jpg)\n![Two](https://example.com/2.jpg)",
            self::md($parser, '<div class="gallery"><img src="/1.jpg" alt="One"><img src="/2.jpg" alt="Two"></div>'),
        );
    }

    #[DataProvider('parsers')]
    public function testUnicodeSurvives(string $parser): void
    {
        // Ā ends in byte 0x80: a byte-wise trim around the line-break placeholder would eat it.
        self::assertSame('**Übersetzung** 🇩🇪 – Ā ✓ **Ā**', self::md($parser, '<p><strong>Übersetzung</strong> 🇩🇪 – Ā ✓ <b>Ā</b></p>'));
    }

    #[DataProvider('parsers')]
    public function testChipsWithoutWhitespaceStaySeparate(string $parser): void
    {
        self::assertSame(
            "Gruppen-Fotoshooting · Team-Fotos · Preise\n\n[Impressum](https://example.com/impressum) · [Datenschutz](https://example.com/datenschutz)",
            self::md($parser, '<div class="tags"><span>Gruppen-Fotoshooting</span><span>Team-Fotos</span><span>Preise</span></div>'
                . '<div><a href="/impressum">Impressum</a><a href="/datenschutz">Datenschutz</a></div>'),
        );
    }

    #[DataProvider('parsers')]
    public function testRunningTextStaysAsWritten(string $parser): void
    {
        // Formatting inside a word, a price and its unit, and a heading animated letter by letter.
        self::assertSame(
            "**Pre***fix* word\n\n29 €/Monat\n\n# Hello",
            self::md($parser, '<p><b>Pre</b><i>fix</i> word</p><div><span>29 €</span><span>/Monat</span></div>'
                . '<h1><span>H</span><span>e</span><span>l</span><span>l</span><span>o</span></h1>'),
        );
    }

}
