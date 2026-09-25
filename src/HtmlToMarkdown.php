<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * Renders a cleaned DOM subtree as CommonMark with GitHub tables and
 * strikethrough.
 *
 * Block elements become blocks separated by a blank line; runs of inline
 * content become paragraphs. Links and images get absolute URLs, because the
 * Markdown is read without the page around it. A block-level link (a card:
 * <a><h3>Title</h3><p>…</p></a>) puts its URL on the first heading or
 * paragraph inside it.
 *
 * Works on nodes from either DOMDocument or Dom\HTMLDocument.
 */
final class HtmlToMarkdown
{
    /**
     * Hard line break placeholder while inline whitespace is collapsed. A
     * control character: text() strips those from the page, so it is unique.
     */
    private const BR = "\x01";

    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dd', 'div', 'dl',
        'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hgroup', 'hr', 'iframe', 'legend', 'li', 'main', 'menu', 'nav', 'ol', 'p', 'pre',
        'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** Elements whose content is never walked. */
    private const SKIP_TAGS = [
        'script', 'style', 'noscript', 'template', 'svg', 'math', 'canvas', 'object', 'embed', 'video',
        'audio', 'source', 'track', 'map', 'area', 'input', 'select', 'textarea', 'option', 'optgroup',
        'datalist', 'link', 'meta', 'base', 'head', 'title',
    ];

    /** The URL a block-level link still has to attach to its first heading or paragraph. */
    private ?string $pendingLink = null;
    /** The pending link waits for a heading: the card has one, and its eyebrow text must not take the link. */
    private bool $linkWantsHeading = false;

    private string $origin;
    private string $basePath;
    private string $baseQuery;
    private string $scheme;
    /** @var array{0: string, 1: string} */
    private array $quotes;

    /**
     * @param string $baseUrl Absolute URL of the page: relative links resolve against it.
     * @param string $lang    Page language; picks the quotation marks for <q>.
     * @param string $images  'alt': images with alt text; 'all': every image; 'none'.
     */
    public function __construct(string $baseUrl, string $lang = '', private string $images = 'alt')
    {
        $parts = parse_url($baseUrl) ?: [];
        $this->scheme = strtolower($parts['scheme'] ?? 'https');
        $this->origin = $this->scheme . '://' . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->basePath = $parts['path'] ?? '/';
        $this->baseQuery = isset($parts['query']) ? '?' . $parts['query'] : '';
        $this->quotes = match (strtolower(substr($lang, 0, 2))) {
            'de' => ['„', '“'],
            'fr' => ['« ', ' »'],
            default => ['“', '”'],
        };
    }

    /** Markdown for the children of $root. */
    public function convert(object $root): string
    {
        $this->pendingLink = null;
        $this->linkWantsHeading = false;

        return self::joinBlocks($this->blocks($root->childNodes));
    }

    // ---------------------------------------------------------------- blocks

    /**
     * @param iterable<object> $nodes
     * @return list<string>
     */
    private function blocks(iterable $nodes): array
    {
        $out = [];
        $inline = '';
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $inline .= $this->text($node->textContent);
                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE || self::skipped($node)) {
                continue;
            }
            if ($this->isBlock($node)) {
                $this->flush($inline, $out);
                foreach ($this->block($node) as $block) {
                    if (trim($block) !== '') {
                        $out[] = $block;
                    }
                }
            } else {
                $inline .= $this->inline($node);
            }
        }
        $this->flush($inline, $out);

        return $out;
    }

    /** @param list<string> $out */
    private function flush(string &$inline, array &$out): void
    {
        $text = $this->finishInline($inline);
        $inline = '';
        if ($text === '') {
            return;
        }
        if (self::isImageOnly($text)) {
            // A gallery reads better one image per line.
            $text = preg_replace('/\)\s*!\[/', ")\n![", $text) ?? $text;
        } elseif ($this->pendingLink !== null && !$this->linkWantsHeading && !str_contains($text, '](')) {
            $text = '[' . $text . '](' . self::encodeUrl($this->pendingLink) . ')';
            $this->pendingLink = null;
        }
        $out[] = $text;
    }

    private function isBlock(object $el): bool
    {
        if (in_array($el->localName, self::BLOCK_TAGS, true) || self::attr($el, 'role') === 'tablist') {
            return true;
        }
        if (self::labelledImage($el) !== null || in_array($el->localName, ['img', 'picture', 'br', 'wbr'], true)) {
            return false;
        }

        // An inline element wrapping blocks (a card link, a span around a list) is a block.
        return $this->hasBlockDescendant($el);
    }

    private function hasBlockDescendant(object $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || self::skipped($child)) {
                continue;
            }
            if (in_array($child->localName, self::BLOCK_TAGS, true) || $this->hasBlockDescendant($child)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function block(object $el): array
    {
        if (self::attr($el, 'role') === 'tablist') {
            return [$this->tabList($el)];
        }

        return match ($el->localName) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => [$this->heading($el)],
            'ul', 'ol', 'menu' => [$this->listBlock($el)],
            'pre' => [$this->codeBlock($el)],
            'table' => $this->table($el),
            'blockquote' => [$this->blockquote($el)],
            'hr' => ['---'],
            'dl' => [$this->definitionList($el)],
            'details' => $this->details($el),
            'figcaption' => [$this->emphasize($this->finishInline($this->inlineChildren($el)))],
            'iframe' => [$this->iframe($el)],
            'a' => $this->blockLink($el),
            default => $this->blocks($el->childNodes),
        };
    }

    private function heading(object $el): string
    {
        $text = str_replace("  \n", ' ', $this->finishInline($this->inlineChildren($el), false));
        // A heading set entirely in bold (editor habit) is already strong.
        if (preg_match('/^\*\*((?:(?!\*\*).)+)\*\*$/u', $text, $m)) {
            $text = $m[1];
        }
        if ($text === '') {
            return '';
        }
        if ($this->pendingLink !== null && !str_contains($text, '](')) {
            $text = '[' . $text . '](' . self::encodeUrl($this->pendingLink) . ')';
            $this->pendingLink = null;
        }

        return str_repeat('#', (int) substr($el->localName, 1)) . ' ' . $text;
    }

    /** @return list<string> */
    private function blockLink(object $el): array
    {
        $url = $this->resolve(self::attr($el, 'href'));
        if ($url === null) {
            return $this->blocks($el->childNodes);
        }
        $saved = [$this->pendingLink, $this->linkWantsHeading];
        $this->pendingLink = $url;
        $this->linkWantsHeading = false;
        foreach (self::descendants($el) as $node) {
            if (in_array($node->localName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $this->linkWantsHeading = true;
                break;
            }
        }
        $blocks = $this->blocks($el->childNodes);
        // Nothing took the link (an image-only card): the content stays, the URL goes.
        [$this->pendingLink, $this->linkWantsHeading] = $saved;

        return $blocks;
    }

    private function listBlock(object $list): string
    {
        $ordered = $list->localName === 'ol';
        $n = $ordered && is_numeric(self::attr($list, 'start')) ? (int) self::attr($list, 'start') : 1;
        $items = [];
        $width = 2;
        foreach ($list->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            if (in_array($child->localName, ['ul', 'ol'], true) && $items !== []) {
                // A list nested straight into a list belongs to the item before it.
                $nested = $this->listBlock($child);
                if ($nested !== '') {
                    $items[array_key_last($items)] .= "\n" . self::indent($nested, $width);
                }
                continue;
            }
            if ($child->localName !== 'li') {
                continue;
            }
            $marker = $ordered ? $n . '. ' : '- ';
            $width = strlen($marker);
            $n++;
            $blocks = $this->blocks($child->childNodes);
            if ($blocks === []) {
                continue;
            }
            $prose = array_filter($blocks, static fn (string $b): bool => !preg_match('/^(\d+\.|-) /', $b));
            $body = implode(count($prose) > 1 ? "\n\n" : "\n", $blocks);
            $items[] = $marker . self::indent($body, strlen($marker), false);
        }

        return implode("\n", $items);
    }

    private function tabList(object $el): string
    {
        $tabs = [];
        foreach (self::descendants($el) as $node) {
            if (self::attr($node, 'role') === 'tab') {
                $label = $this->finishInline($this->inlineChildren($node));
                if ($label !== '') {
                    $tabs[] = '- ' . $label;
                }
            }
        }

        return implode("\n", $tabs);
    }

    private function codeBlock(object $pre): string
    {
        $code = $pre->textContent;
        $lang = '';
        foreach ($pre->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'code'
                && preg_match('/(?:^|\s)(?:language|lang)-([\w+#-]+)/', self::attr($child, 'class'), $m)) {
                $lang = $m[1];
            }
        }
        $code = rtrim(str_replace("\r\n", "\n", $code), "\n");
        if (trim($code) === '') {
            return '';
        }
        $fence = '```';
        while (str_contains($code, $fence)) {
            $fence .= '`';
        }

        return $fence . $lang . "\n" . $code . "\n" . $fence;
    }

    private function blockquote(object $el): string
    {
        $inner = self::joinBlocks($this->blocks($el->childNodes));
        if ($inner === '') {
            return '';
        }

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $inner),
        ));
    }

    private function definitionList(object $dl): string
    {
        $groups = [];
        $current = [];
        $hasDefinition = false;
        $walk = function (object $parent) use (&$walk, &$groups, &$current, &$hasDefinition): void {
            foreach ($parent->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                if ($child->localName === 'div') {
                    $walk($child);
                } elseif ($child->localName === 'dt') {
                    if ($hasDefinition) {
                        $groups[] = $current;
                        $current = [];
                        $hasDefinition = false;
                    }
                    $term = str_replace("  \n", ' ', $this->finishInline($this->inlineChildren($child), false));
                    if ($term !== '') {
                        $current[] = $this->strong($term);
                    }
                } elseif ($child->localName === 'dd') {
                    $text = self::joinBlocks($this->blocks($child->childNodes));
                    if ($text !== '') {
                        $current[] = $text;
                        $hasDefinition = true;
                    }
                }
            }
        };
        $walk($dl);
        if ($current !== []) {
            $groups[] = $current;
        }

        return implode("\n\n", array_map(static fn (array $g): string => implode("\n", $g), $groups));
    }

    /** @return list<string> */
    private function details(object $el): array
    {
        $out = [];
        $rest = [];
        $summarySeen = false;
        foreach ($el->childNodes as $child) {
            if (!$summarySeen && $child->nodeType === XML_ELEMENT_NODE && $child->localName === 'summary') {
                $summarySeen = true;
                if ($this->hasBlockDescendant($child)) {
                    array_push($out, ...$this->blocks($child->childNodes));
                } else {
                    $label = str_replace("  \n", ' ', $this->finishInline($this->inlineChildren($child)));
                    if ($label !== '') {
                        $out[] = $this->strong($label);
                    }
                }
                continue;
            }
            $rest[] = $child;
        }

        return [...$out, ...$this->blocks($rest)];
    }

    private function iframe(object $el): string
    {
        $title = trim((string) self::attr($el, 'title'));
        $url = $this->resolve((string) self::attr($el, 'src'));
        if ($title === '' || $url === null || !preg_match('#^https?://#', $url)) {
            return '';
        }

        return '[' . $this->escape($title) . '](' . self::encodeUrl($url) . ')';
    }

    /** @return list<string> */
    private function table(object $table): array
    {
        $caption = '';
        $rows = [];
        $collect = function (object $parent) use (&$collect, &$rows, &$caption): void {
            foreach ($parent->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                match ($child->localName) {
                    'caption' => $caption = $this->finishInline($this->inlineChildren($child)),
                    'thead', 'tbody', 'tfoot' => $collect($child),
                    'tr' => $rows[] = $this->tableRow($child),
                    default => null,
                };
            }
        };
        $collect($table);
        $rows = array_values(array_filter($rows, static fn (array $cells): bool => $cells !== []));
        if ($rows === []) {
            return [];
        }

        $width = max(array_map('count', $rows));
        $line = static fn (array $cells): string => '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';

        // GitHub tables need a header row; a table without one uses its first row.
        $lines = [$line(array_shift($rows)), '|' . str_repeat(' --- |', $width)];
        foreach ($rows as $row) {
            $lines[] = $line($row);
        }
        $out = [];
        if ($caption !== '') {
            $out[] = $this->strong($caption);
        }
        $out[] = implode("\n", $lines);

        return $out;
    }

    /** @return list<string> */
    private function tableRow(object $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $cell) {
            if ($cell->nodeType !== XML_ELEMENT_NODE || !in_array($cell->localName, ['td', 'th'], true)) {
                continue;
            }
            $text = implode(' <br> ', array_map(
                static fn (string $b): string => str_replace("\n", ' ', $b),
                $this->blocks($cell->childNodes),
            ));
            $cells[] = str_replace('|', '\|', trim(preg_replace('/ {2,}/', ' ', $text) ?? $text));
            $span = (int) self::attr($cell, 'colspan');
            for ($i = 1; $i < min($span, 50); $i++) {
                $cells[] = '';
            }
        }

        return $cells;
    }

    // ---------------------------------------------------------------- inline

    private function inline(object $el): string
    {
        $label = self::labelledImage($el);
        if ($label !== null) {
            return ' ' . $this->escape($label) . ' ';
        }

        return match ($el->localName) {
            'br' => self::BR,
            'wbr' => '',
            'img' => $this->image($el),
            'picture' => $this->picture($el),
            'a' => $this->link($el),
            'strong', 'b' => $this->wrap($this->inlineChildren($el), '**'),
            'em', 'i', 'cite', 'dfn' => $this->wrap($this->inlineChildren($el), '*'),
            'del', 's', 'strike' => $this->wrap($this->inlineChildren($el), '~~'),
            'code', 'kbd', 'samp', 'tt', 'var' => $this->code($el->textContent),
            'q' => $this->quotes[0] . trim($this->inlineChildren($el)) . $this->quotes[1],
            'button' => self::attr($el, 'role') === 'tab' ? $this->inlineChildren($el) : '',
            default => $this->inlineChildren($el),
        };
    }

    private function inlineChildren(object $el): string
    {
        $out = '';
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $out .= $this->text($child->textContent);
            } elseif ($child->nodeType === XML_ELEMENT_NODE && !self::skipped($child)) {
                $out .= $this->isBlock($child)
                    ? ' ' . str_replace("\n", ' ', self::joinBlocks($this->block($child))) . ' '
                    : $this->inline($child);
            }
        }

        return $out;
    }

    private function link(object $el): string
    {
        $inner = $this->inlineChildren($el);
        $plain = trim(preg_replace('/\s+/u', ' ', str_replace(self::BR, ' ', $inner)) ?? '');
        if ($plain === '') {
            $label = trim((string) (self::attr($el, 'aria-label') ?: self::attr($el, 'title')));
            if ($label === '') {
                return $inner;
            }
            $inner = $plain = $this->escape($label);
        }
        $url = $this->resolve((string) self::attr($el, 'href'));
        if ($url === null || str_contains($inner, '](')) {
            return $inner;
        }
        if (preg_match('#^https?://#i', $url) && self::unescape($plain) === $url) {
            return '<' . $url . '>';
        }
        if (preg_match('/^mailto:([^?]+)$/i', $url, $mail) && self::unescape($plain) === $mail[1]) {
            return '<' . $mail[1] . '>';
        }
        preg_match('/^(\s*)(.*?)(\s*)$/su', $inner, $m);

        return $m[1] . '[' . $m[2] . '](' . self::encodeUrl($url) . ')' . $m[3];
    }

    private function image(object $img): string
    {
        if ($this->images === 'none') {
            return '';
        }
        $alt = trim(preg_replace('/\s+/u', ' ', (string) self::attr($img, 'alt')) ?? '');
        if ($alt === '' && $this->images !== 'all') {
            return '';
        }
        // Lazy loaders park a data: placeholder in src and the real file elsewhere.
        $src = '';
        foreach (['src', 'data-src', 'data-lazy-src', 'srcset', 'data-srcset'] as $name) {
            $candidate = trim(self::attr($img, $name));
            if (str_ends_with($name, 'srcset')) {
                $candidate = trim(explode(' ', trim(explode(',', $candidate)[0]))[0]);
            }
            if ($candidate !== '' && !str_starts_with($candidate, 'data:')) {
                $src = $candidate;
                break;
            }
        }
        $url = $src === '' ? null : $this->resolve($src);
        if ($url === null) {
            return $alt === '' ? '' : $this->escape($alt);
        }

        return '![' . $this->escape($alt) . '](' . self::encodeUrl($url) . ')';
    }

    private function picture(object $picture): string
    {
        foreach (self::descendants($picture) as $node) {
            if ($node->localName === 'img') {
                return $this->image($node);
            }
        }

        return '';
    }

    private function code(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }
        $fence = '`';
        while (str_contains($text, $fence)) {
            $fence .= '`';
        }
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $pad . $text . $pad . $fence;
    }

    /** Wraps inline Markdown in emphasis markers, keeping outer whitespace outside. */
    private function wrap(string $inner, string $marker): string
    {
        if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $inner, $m) || $m[2] === '') {
            return $inner;
        }
        if (str_starts_with($m[2], $marker) && str_ends_with($m[2], $marker) && strlen($m[2]) > 2 * strlen($marker)) {
            return $inner;
        }
        // Emphasis never starts or ends at a hard break: keep those outside.
        $lead = substr($m[2], 0, strspn($m[2], self::BR . ' '));
        $core = trim($m[2], self::BR . ' ');
        $tail = substr($m[2], strlen($lead) + strlen($core));

        return $m[1] . $lead . $marker . $core . $marker . $tail . $m[3];
    }

    private function strong(string $text): string
    {
        return str_starts_with($text, '**') && str_ends_with($text, '**') ? $text : '**' . $text . '**';
    }

    private function emphasize(string $text): string
    {
        return $text === '' ? '' : '*' . $text . '*';
    }

    // -------------------------------------------------------------- text

    /** A text node, cleaned and escaped; whitespace is collapsed per paragraph later. */
    private function text(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $text) ?? $text;
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}"], ' ', $text);
        $text = str_replace(["\u{00AD}", "\u{200B}", "\u{FEFF}"], '', $text);

        return $this->escape($text);
    }

    private function escape(string $text): string
    {
        // Backslash first, and only where it would escape something.
        $text = preg_replace('/\\\\(?=[!-\/:-@\[-`{-~])/', '\\\\\\\\', $text) ?? $text;
        $text = preg_replace('/([*`\[\]~])/', '\\\\$1', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\_', $text) ?? $text;
        $text = preg_replace('/<(?=[a-zA-Z\/!?])/', '\\<', $text) ?? $text;

        return preg_replace('/&(?=#?[a-zA-Z0-9]+;)/', '\\&', $text) ?? $text;
    }

    private static function unescape(string $text): string
    {
        return preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $text) ?? $text;
    }

    /**
     * Collapses whitespace, turns hard breaks into Markdown ones, and guards
     * line starts. Headings, table cells and terms pass $guard = false: text
     * there cannot start a block.
     */
    private function finishInline(string $inline, bool $guard = true): string
    {
        $text = preg_replace('/[ \t\n\r\f]+/', ' ', $inline) ?? $inline;
        $text = preg_replace('/ *' . self::BR . ' */', self::BR, $text) ?? $text;
        $text = trim($text, self::BR . ' ');
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/' . self::BR . '+/', self::BR, $text) ?? $text;
        $lines = explode(self::BR, $text);
        if ($guard) {
            $lines = array_map(self::guardLineStart(...), $lines);
        }

        return implode("  \n", array_map('trim', $lines));
    }

    /** Escapes text that Markdown would read as the start of a block. */
    private static function guardLineStart(string $line): string
    {
        $line = trim($line);
        if (preg_match('/^(#{1,6}|>|[-+])(\s|$)/', $line) || preg_match('/^(=+|-+)$/', $line)) {
            return '\\' . $line;
        }
        if (preg_match('/^(\d{1,9})([.)])(\s|$)/', $line, $m)) {
            return $m[1] . '\\' . substr($line, strlen($m[1]));
        }

        return $line;
    }

    // --------------------------------------------------------------- urls

    public function resolve(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/^(?:javascript|data|vbscript|blob):/i', $href)) {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return $this->scheme . ':' . $href;
        }
        if ($href[0] === '#') {
            return $this->origin . $this->basePath . $this->baseQuery . $href;
        }
        if ($href[0] === '?') {
            return $this->origin . $this->basePath . $href;
        }
        $suffix = '';
        if (preg_match('/[?#]/', $href, $m, PREG_OFFSET_CAPTURE)) {
            $suffix = substr($href, $m[0][1]);
            $href = substr($href, 0, $m[0][1]);
        }
        $path = str_starts_with($href, '/')
            ? $href
            : substr($this->basePath, 0, (int) strrpos($this->basePath, '/') + 1) . $href;

        return $this->origin . self::removeDotSegments($path) . $suffix;
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $result = implode('/', $out);
        if (preg_match('#/\.\.?$#', $path)) {
            $result .= '/';
        }

        return $result === '' ? '/' : $result;
    }

    private static function encodeUrl(string $url): string
    {
        return str_replace([' ', '(', ')', '<', '>'], ['%20', '%28', '%29', '%3C', '%3E'], $url);
    }

    // ------------------------------------------------------------- helpers

    /** An attribute value; '' when absent (Dom\Element answers null, DOMElement ''). */
    private static function attr(object $el, string $name): string
    {
        return (string) $el->getAttribute($name);
    }


    /** Content that is never walked; a labelled role="img" still speaks through its label. */
    private static function skipped(object $el): bool
    {
        return in_array($el->localName, self::SKIP_TAGS, true) && self::labelledImage($el) === null;
    }

    /** The accessible name of a role="img" element (a star rating, an icon with a label). */
    private static function labelledImage(object $el): ?string
    {
        if (self::attr($el, 'role') !== 'img') {
            return null;
        }
        $label = trim((string) self::attr($el, 'aria-label'));

        return $label === '' ? null : $label;
    }

    private static function isImageOnly(string $text): bool
    {
        return (bool) preg_match('/^(?:!\[[^\]]*\]\([^)]*\)\s*)+$/', $text);
    }

    /** @return \Generator<object> */
    private static function descendants(object $el): \Generator
    {
        foreach ($el->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                yield $child;
                yield from self::descendants($child);
            }
        }
    }

    private static function indent(string $text, int $width, bool $firstLine = true): string
    {
        $pad = str_repeat(' ', $width);
        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if (($i > 0 || $firstLine) && $line !== '') {
                $lines[$i] = $pad . $line;
            }
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $blocks */
    private static function joinBlocks(array $blocks): string
    {
        return trim(implode("\n\n", array_filter($blocks, static fn (string $b): bool => trim($b) !== '')), "\n");
    }
}
