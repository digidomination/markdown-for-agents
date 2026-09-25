<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * Translates the small CSS selector subset the options use into XPath 1.0.
 *
 * Supported: type (div), universal (*), #id, .class, [attr], [attr=v],
 * [attr~=v], [attr^=v], [attr$=v], [attr*=v], compounds of those
 * (a.btn[href^="/"]), the descendant (space) and child (>) combinators, and
 * comma-separated lists. No pseudo-classes: a selector that needs one is
 * better expressed as data-md="skip" in the template.
 */
final class Selector
{
    /**
     * @param string $context XPath prefix the selector is anchored to:
     *                        './/' searches below the context node.
     */
    public static function toXPath(string $selector, string $context = './/'): string
    {
        $alternatives = [];
        foreach (self::splitList($selector) as $one) {
            $alternatives[] = self::single($one, $context);
        }
        if ($alternatives === []) {
            throw new \InvalidArgumentException('Empty selector');
        }

        return implode(' | ', $alternatives);
    }

    /** @return list<string> */
    private static function splitList(string $selector): array
    {
        $out = [];
        $depth = 0;
        $quote = null;
        $buf = '';
        $len = strlen($selector);
        for ($i = 0; $i < $len; $i++) {
            $c = $selector[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }

        return $out;
    }

    private static function single(string $selector, string $context): string
    {
        // Tokenise into compounds and combinators, respecting [..] and quotes.
        $tokens = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($selector);
        for ($i = 0; $i < $len; $i++) {
            $c = $selector[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
            }
            if ($depth === 0 && ($c === ' ' || $c === '>' || $c === "\t" || $c === "\n")) {
                if ($buf !== '') {
                    $tokens[] = $buf;
                    $buf = '';
                }
                if ($c === '>') {
                    $tokens[] = '>';
                }
                continue;
            }
            $buf .= $c;
        }
        if ($buf !== '') {
            $tokens[] = $buf;
        }

        $xpath = '';
        $axis = $context;
        foreach ($tokens as $token) {
            if ($token === '>') {
                $axis = '/';
                continue;
            }
            $prefix = match (true) {
                $xpath !== '' => $axis === '/' ? '/' : '//',
                $axis === '/' => './',   // "> li": a child of the context node
                default => $axis,
            };
            $xpath .= $prefix . self::compound($token);
            $axis = '//';
        }
        if ($xpath === '') {
            throw new \InvalidArgumentException("Cannot parse selector: $selector");
        }

        return $xpath;
    }

    private static function compound(string $compound): string
    {
        if (!preg_match('/^(\*|[a-zA-Z][a-zA-Z0-9-]*)?/', $compound, $m)) {
            throw new \InvalidArgumentException("Cannot parse selector: $compound");
        }
        $tag = ($m[1] ?? '') === '' ? '*' : strtolower($m[1]);
        $rest = substr($compound, strlen($m[0]));
        $predicates = '';

        while ($rest !== '') {
            if (preg_match('/^#([\w-]+)/u', $rest, $m)) {
                $predicates .= '[@id=' . self::literal($m[1]) . ']';
            } elseif (preg_match('/^\.([\w-]+)/u', $rest, $m)) {
                $predicates .= '[contains(concat(" ", normalize-space(@class), " "), ' . self::literal(' ' . $m[1] . ' ') . ')]';
            } elseif (preg_match('/^\[\s*([\w:-]+)\s*(?:([~^$*]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/u', $rest, $m)) {
                $attr = '@' . strtolower($m[1]);
                $op = $m[2] ?? '';
                $value = ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? ''));
                $lit = self::literal($value);
                $predicates .= match ($op) {
                    '' => "[$attr]",
                    '=' => "[$attr=$lit]",
                    '~=' => '[contains(concat(" ", normalize-space(' . $attr . '), " "), ' . self::literal(' ' . $value . ' ') . ')]',
                    '^=' => "[starts-with($attr, $lit)]",
                    '$=' => "[substring($attr, string-length($attr) - string-length($lit) + 1) = $lit]",
                    '*=' => "[contains($attr, $lit)]",
                };
            } else {
                throw new \InvalidArgumentException("Unsupported selector part: $rest");
            }
            $rest = substr($rest, strlen($m[0]));
        }

        return $tag . $predicates;
    }

    /** An XPath 1.0 string literal for any value, quotes included. */
    private static function literal(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        $parts = array_map(static fn (string $p): string => "'" . $p . "'", explode("'", $value));

        return 'concat(' . implode(", \"'\", ", $parts) . ')';
    }
}
