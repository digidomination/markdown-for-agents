<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * Parses an HTML page into a DOM plus an XPath evaluator.
 *
 * PHP 8.4+ ships a real HTML5 parser (Dom\HTMLDocument), which is used when
 * present. Older PHP falls back to libxml's HTML parser. Both hand back
 * elements with lowercase localName, getAttribute() and childNodes, which is
 * all the converter touches, so either tree works.
 */
final class Html
{
    public const PARSER_AUTO = 'auto';
    public const PARSER_HTML5 = 'html5';
    public const PARSER_LIBXML = 'libxml';

    /** @return array{0: \DOMDocument|\Dom\HTMLDocument, 1: \DOMXPath|\Dom\XPath} */
    public static function parse(string $html, string $parser = self::PARSER_AUTO): array
    {
        $html5 = class_exists(\Dom\HTMLDocument::class);
        if ($parser === self::PARSER_HTML5 && !$html5) {
            throw new \RuntimeException('The HTML5 parser needs PHP 8.4 or newer');
        }
        if ($html5 && $parser !== self::PARSER_LIBXML) {
            $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');

            return [$doc, new \Dom\XPath($doc)];
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // libxml reads bytes as Latin-1 unless told otherwise. Numeric
            // character references are unambiguous under every libxml version.
            $encoded = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
            $doc->loadHTML($encoded, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [$doc, new \DOMXPath($doc)];
    }

    /**
     * The text of a raw-text element (a JSON-LD <script>). libxml gets the page
     * with every non-ASCII character as a numeric reference, and raw text is
     * never decoded by the parser, so it is decoded here.
     */
    public static function rawText(object $node): string
    {
        $text = (string) $node->textContent;

        return $node instanceof \DOMNode
            ? mb_decode_numericentity($text, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8')
            : $text;
    }
}
