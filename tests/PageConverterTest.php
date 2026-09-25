<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use Digidomination\MarkdownForAgents\PageConverter;
use PHPUnit\Framework\Attributes\DataProvider;

final class PageConverterTest extends ParserCase
{
    private const PAGE = <<<'HTML'
        <!DOCTYPE html>
        <html lang="de-DE">
        <head>
          <meta charset="utf-8">
          <title>Preise – Beispiel GmbH</title>
          <meta name="description" content="Alle Preise: fest, netto, ohne Überraschung.">
          <link rel="canonical" href="https://example.com/preise">
          <link rel="alternate" hreflang="de-DE" href="https://example.com/preise">
          <link rel="alternate" hreflang="en" href="https://example.com/en/pricing">
          <meta property="og:image" content="https://example.com/og.png">
          <meta property="article:modified_time" content="2026-09-01T10:00:00Z">
          <script type="application/ld+json">
            {"@context": "https://schema.org", "@graph": [
              {"@type": "WebPage", "name": "Preise"},
              {"@type": "ProfessionalService", "name": "Beispiel GmbH",
               "address": {"@type": "PostalAddress", "streetAddress": "Weg 1", "postalCode": "10115", "addressLocality": "Berlin", "addressCountry": "DE"},
               "contactPoint": {"@type": "ContactPoint", "telephone": "+49 30 123", "email": "mailto:hallo@example.com"}}
            ]}
          </script>
        </head>
        <body>
          <header class="site"><nav><a href="/">Start</a></nav><p>Menu text</p></header>
          <main>
            <h1>Preise</h1>
            <p>Sichtbar.</p>
            <p hidden>Versteckt.</p>
            <p aria-hidden="true">Dekoration.</p>
            <div data-md="skip">Nur fürs Auge.</div>
            <div class="promo">Werbung.</div>
            <form><label>E-Mail <input name="e"></label><button>Senden</button></form>
            <nav aria-label="Inhalt"><a href="#a">Inhaltsverzeichnis</a></nav>
            <div role="dialog"><p>Modal.</p></div>
            <button type="button">Menü öffnen</button>
            <script>var x = "<p>nope</p>";</script>
            <style>.x{}</style>
            <p>Ende.</p>
          </main>
          <footer><p>Impressum</p></footer>
        </body>
        </html>
        HTML;

    #[DataProvider('parsers')]
    public function testDocument(string $parser): void
    {
        $result = (new PageConverter(['parser' => $parser, 'exclude' => ['.promo']]))->convert(self::PAGE, 'https://example.com/preise?x=1');

        self::assertSame(<<<'MD'
            ---
            title: "Preise – Beispiel GmbH"
            description: "Alle Preise: fest, netto, ohne Überraschung."
            url: "https://example.com/preise"
            language: de-DE
            alternates:
              de-DE: "https://example.com/preise"
              en: "https://example.com/en/pricing"
            image: "https://example.com/og.png"
            modified: "2026-09-01T10:00:00Z"
            organization:
              name: Beispiel GmbH
              telephone: "+49 30 123"
              email: "hallo@example.com"
              address: Weg 1, 10115 Berlin, DE
            ---

            # Preise

            Sichtbar.

            Ende.

            MD, $result['markdown']);
        self::assertFalse($result['meta']['noindex']);
    }

    #[DataProvider('parsers')]
    public function testJsonLdOnRequest(string $parser): void
    {
        $markdown = (new PageConverter(['parser' => $parser, 'json_ld' => true, 'frontmatter' => false]))
            ->convert(self::PAGE, 'https://example.com/preise')['markdown'];

        self::assertStringContainsString("```json\n{\n    \"@context\": \"https://schema.org\",", $markdown);
        self::assertStringEndsWith("```\n", $markdown);
    }

    #[DataProvider('parsers')]
    public function testWithoutMainTheBodyLosesItsChrome(string $parser): void
    {
        $html = '<html><body><header><p>Logo</p></header><article><header><h1>Post</h1></header><p>Text</p>'
            . '<footer>Tags: a</footer></article><aside>Sidebar</aside><footer>Site footer</footer></body></html>';

        self::assertSame(
            "# Post\n\nText\n\nTags: a\n",
            (new PageConverter(['parser' => $parser, 'frontmatter' => false, 'main' => ['main', 'body']]))
                ->convert($html, 'https://example.com/post')['markdown'],
        );
    }

    #[DataProvider('parsers')]
    public function testNoindexAndMissingCanonical(string $parser): void
    {
        $html = '<html><head><meta name="robots" content="noindex, follow"></head><body><main><a href="x">X</a></main></body></html>';
        $result = (new PageConverter(['parser' => $parser, 'frontmatter' => false]))->convert($html, 'https://example.com/a/b');

        self::assertTrue($result['meta']['noindex']);
        self::assertSame("[X](https://example.com/a/x)\n", $result['markdown']);
    }

    public function testOrganizationFromPlainJsonLd(): void
    {
        self::assertSame(
            ['name' => 'Studio', 'telephone' => '+49 40 1', 'address' => 'Hamburg'],
            PageConverter::organization([
                ['@type' => 'Article', 'name' => 'Post', 'publisher' => ['@type' => 'LocalBusiness', 'name' => 'Studio', 'telephone' => 'tel:+49 40 1', 'address' => 'Hamburg']],
            ]),
        );
        self::assertSame([], PageConverter::organization([['@type' => 'Person', 'name' => 'Someone']]));
    }
}
