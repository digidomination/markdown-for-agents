<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * Decides whether a request asks for Markdown, and maps page paths to their
 * Markdown twins and back.
 *
 * Two ways in:
 *  - content negotiation: the client lists text/markdown in its Accept header
 *    at least as high as text/html (browsers never list it, so they always
 *    get HTML);
 *  - the llms.txt convention: the page URL with ".md" appended, and
 *    "index.html.md" for a URL that ends in a slash.
 */
final class Negotiator
{
    private const MARKDOWN_TYPES = ['text/markdown', 'text/x-markdown'];

    /**
     * True when the Accept header names Markdown explicitly and ranks it at
     * least as high as HTML. A wildcard never counts as a request for
     * Markdown: only a client that asks for it by name gets it.
     */
    public static function prefersMarkdown(?string $accept): bool
    {
        if ($accept === null || trim($accept) === '') {
            return false;
        }

        $markdown = 0.0;
        // Quality for text/html by the most specific range that covers it.
        $html = ['exact' => null, 'text' => null, 'any' => null];

        foreach (explode(',', $accept) as $range) {
            $parts = explode(';', $range);
            $type = strtolower(trim(array_shift($parts)));
            if ($type === '') {
                continue;
            }
            $q = 1.0;
            foreach ($parts as $param) {
                $kv = explode('=', $param, 2);
                if (count($kv) === 2 && strtolower(trim($kv[0])) === 'q') {
                    $q = max(0.0, min(1.0, (float) trim($kv[1])));
                }
            }

            if (in_array($type, self::MARKDOWN_TYPES, true)) {
                $markdown = max($markdown, $q);
            } elseif ($type === 'text/html') {
                $html['exact'] = max($html['exact'] ?? 0.0, $q);
            } elseif ($type === 'text/*') {
                $html['text'] = max($html['text'] ?? 0.0, $q);
            } elseif ($type === '*/*') {
                $html['any'] = max($html['any'] ?? 0.0, $q);
            }
        }

        $htmlQ = $html['exact'] ?? $html['text'] ?? $html['any'] ?? 0.0;

        return $markdown > 0.0 && $markdown >= $htmlQ;
    }

    /** "/" → "/index.html.md", "/about" → "/about.md", "/docs/" → "/docs/index.html.md". */
    public static function markdownPath(string $htmlPath): string
    {
        if ($htmlPath === '' || str_ends_with($htmlPath, '/')) {
            return ($htmlPath === '' ? '/' : $htmlPath) . 'index.html.md';
        }

        return $htmlPath . '.md';
    }

    /**
     * The page path behind a Markdown path, or null when the path is not one.
     * "/index.md" is accepted as an alias of "/index.html.md".
     */
    public static function htmlPath(string $path): ?string
    {
        if (!str_ends_with($path, '.md') || str_ends_with($path, '/.md')) {
            return null;
        }
        foreach (['/index.html.md', '/index.md'] as $index) {
            if (str_ends_with($path, $index)) {
                return substr($path, 0, -strlen($index)) . '/';
            }
        }
        $html = substr($path, 0, -3);

        return $html === '' ? null : $html;
    }
}
