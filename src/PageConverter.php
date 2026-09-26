<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * Turns a whole HTML page into a Markdown document: YAML frontmatter from the
 * page's own metadata, the main content as Markdown, and the page's JSON-LD
 * as a fenced block.
 *
 * Only the content root is converted (by default <main>): the site header,
 * navigation, footer, cookie banner and scripts never reach the output.
 * Inside it, interactive and hidden parts are dropped: forms, dialogs,
 * buttons, anything hidden or aria-hidden, and whatever the site marks with
 * data-md="skip" or lists under "exclude".
 */
final class PageConverter
{
    /** Removed inside the content root before conversion. */
    public const REMOVE = [
        'script', 'style', 'noscript', 'template', 'canvas', 'object', 'embed', 'video', 'audio',
        'form', 'input', 'select', 'textarea', 'label', 'datalist', 'output', 'progress', 'meter',
        'nav', 'dialog', '[hidden]', '[aria-hidden=true]', '[role=dialog]', '[aria-modal=true]',
        '[role=navigation]', '[role=search]', '[data-md=skip]',
    ];

    /** @var array{main: list<string>, exclude: list<string>, images: string, frontmatter: bool, json_ld: bool, organization: bool, parser: string} */
    private array $options;

    /** @param array<string, mixed> $options see MarkdownForAgents::DEFAULTS */
    public function __construct(array $options = [])
    {
        $this->options = [
            'main' => array_values((array) ($options['main'] ?? ['main', '[role=main]', 'article', 'body'])),
            'exclude' => array_values((array) ($options['exclude'] ?? [])),
            'images' => (string) ($options['images'] ?? 'alt'),
            'frontmatter' => (bool) ($options['frontmatter'] ?? true),
            'json_ld' => (bool) ($options['json_ld'] ?? false),
            'organization' => (bool) ($options['organization'] ?? true),
            'parser' => (string) ($options['parser'] ?? Html::PARSER_AUTO),
        ];
    }

    /**
     * @param string $url Absolute URL of the page, used when it declares no canonical.
     * @return array{markdown: string, meta: array<string, mixed>}
     */
    public function convert(string $html, string $url): array
    {
        [$doc, $xpath] = Html::parse($html, $this->options['parser']);
        $meta = $this->metadata($xpath, $url);

        $root = $this->contentRoot($xpath);
        $body = '';
        if ($root !== null) {
            $this->clean($xpath, $root);
            $body = (new HtmlToMarkdown($meta['url'], (string) ($meta['language'] ?? ''), $this->options['images']))
                ->convert($root);
        }

        $parts = [];
        if ($this->options['frontmatter']) {
            $parts[] = self::frontmatter($meta, $this->options['organization'] ? self::organization($meta['json_ld']) : []);
        }
        if ($body !== '') {
            $parts[] = $body;
        }
        if ($this->options['json_ld'] && $meta['json_ld'] !== []) {
            $json = json_encode(
                count($meta['json_ld']) === 1 ? $meta['json_ld'][0] : $meta['json_ld'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            );
            if (is_string($json)) {
                $parts[] = "```json\n" . $json . "\n```";
            }
        }

        return ['markdown' => implode("\n\n", $parts) . "\n", 'meta' => $meta];
    }

    /** @return array<string, mixed> */
    private function metadata(object $xpath, string $url): array
    {
        $first = static function (string $query) use ($xpath): string {
            $nodes = $xpath->query($query);
            if ($nodes === false || $nodes->length === 0) {
                return '';
            }

            return trim(preg_replace('/\s+/u', ' ', (string) $nodes->item(0)->textContent) ?? '');
        };
        $rel = static fn (string $token): string => '//link[contains(concat(" ", normalize-space(@rel), " "), " ' . $token . ' ")]';

        $canonical = $first($rel('canonical') . '/@href');
        $meta = [
            'title' => $first('//title'),
            'description' => $first('//meta[@name="description"]/@content'),
            'url' => preg_match('#^https?://#i', $canonical) ? $canonical : $url,
            'language' => $first('/html/@lang') ?: $first('//html/@lang'),
            'alternates' => [],
            'image' => $first('//meta[@property="og:image"]/@content'),
            'author' => $first('//meta[@name="author"]/@content'),
            'published' => $first('//meta[@property="article:published_time"]/@content'),
            'modified' => $first('//meta[@property="article:modified_time"]/@content'),
            'noindex' => str_contains(strtolower($first('//meta[@name="robots"]/@content')), 'noindex'),
            'json_ld' => [],
        ];

        foreach ($xpath->query($rel('alternate') . '[@hreflang][@href]') ?: [] as $link) {
            $lang = trim((string) self::attr($link, 'hreflang'));
            $href = trim((string) self::attr($link, 'href'));
            if ($lang !== '' && preg_match('#^https?://#i', $href)) {
                $meta['alternates'][$lang] = $href;
            }
        }

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode(trim(Html::rawText($script)), true);
            if (is_array($data)) {
                $meta['json_ld'][] = $data;
            }
        }

        return $meta;
    }

    private function contentRoot(object $xpath): ?object
    {
        foreach ($this->options['main'] as $selector) {
            $nodes = $xpath->query(Selector::toXPath($selector, '//'));
            if ($nodes !== false && $nodes->length > 0) {
                return $nodes->item(0);
            }
        }

        return null;
    }

    private function clean(object $xpath, object $root): void
    {
        $selectors = [...self::REMOVE, ...$this->options['exclude']];
        // Without a <main> the page chrome sits inside the root: drop the
        // header, footer and asides that are not part of an article.
        if ($root->localName === 'body') {
            $selectors[] = 'header';
            $selectors[] = 'footer';
            $selectors[] = 'aside';
        }

        $doomed = [];
        foreach ($selectors as $selector) {
            foreach ($xpath->query(Selector::toXPath($selector), $root) ?: [] as $node) {
                if ($root->localName === 'body' && in_array($node->localName, ['header', 'footer', 'aside'], true)
                    && $xpath->query('ancestor::article | ancestor::main', $node)->length > 0) {
                    continue;
                }
                $doomed[] = $node;
            }
        }
        // Buttons are controls, except tabs: a tab's label names the panel it shows.
        foreach ($xpath->query('.//button[not(@role="tab")]', $root) ?: [] as $node) {
            $doomed[] = $node;
        }

        foreach ($doomed as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Contact facts of the business behind the page, from its JSON-LD. The
     * footer that shows them to people is stripped, and an agent asked "how
     * do I reach them?" needs exactly these.
     *
     * @param list<array<mixed>> $jsonLd
     * @return array<string, string>
     */
    public static function organization(array $jsonLd): array
    {
        $nodes = [];
        $walk = static function (mixed $node) use (&$walk, &$nodes): void {
            if (!is_array($node)) {
                return;
            }
            if (array_is_list($node)) {
                array_map($walk, $node);

                return;
            }
            if (isset($node['@type'], $node['name'])) {
                $nodes[] = $node;
            }
            foreach (['@graph', 'publisher', 'provider', 'author', 'brand', 'mainEntity', 'about'] as $key) {
                if (isset($node[$key])) {
                    $walk($node[$key]);
                }
            }
        };
        $walk($jsonLd);

        $best = null;
        $bestScore = 0;
        foreach ($nodes as $node) {
            $types = array_map('strval', (array) $node['@type']);
            $isOrg = (bool) array_filter($types, static fn (string $t): bool => (bool) preg_match('/Organization|Business|Service$|Store|Corporation|Agency|Studio|Restaurant|Office/i', $t));
            $score = ($isOrg ? 2 : 0) + (isset($node['telephone']) ? 1 : 0) + (isset($node['email']) ? 1 : 0) + (isset($node['address']) ? 1 : 0);
            if ($isOrg && $score > $bestScore) {
                $best = $node;
                $bestScore = $score;
            }
        }
        if ($best === null) {
            return [];
        }

        $address = $best['address'] ?? null;
        if (is_array($address) && !array_is_list($address)) {
            $field = static fn (string $key): string => trim((string) ($address[$key] ?? ''));
            $country = trim(is_array($address['addressCountry'] ?? null) ? (string) ($address['addressCountry']['name'] ?? '') : $field('addressCountry'));
            // Most of Europe writes "10115 Berlin". The US, Canada and Australia write
            // "Saint Paul, Minnesota 55101"; the UK and Ireland put the postcode after the town.
            $parts = match (strtoupper($country)) {
                'US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA', 'CA', 'CANADA', 'AU', 'AUSTRALIA'
                    => [$field('streetAddress'), $field('addressLocality'), trim($field('addressRegion') . ' ' . $field('postalCode')), $country],
                'GB', 'UK', 'UNITED KINGDOM', 'IE', 'IRELAND'
                    => [$field('streetAddress'), $field('addressLocality'), $field('addressRegion'), $field('postalCode'), $country],
                default => [$field('streetAddress'), trim($field('postalCode') . ' ' . $field('addressLocality')), $field('addressRegion'), $country],
            };
            $line = implode(', ', array_filter($parts, static fn (string $p): bool => $p !== ''));
        } else {
            $line = is_string($address) ? trim($address) : '';
        }

        // Phone and email may sit on the organisation or on its first contact point.
        $contact = $best['contactPoint'] ?? [];
        $contact = is_array($contact) && array_is_list($contact) ? ($contact[0] ?? []) : $contact;
        $pick = static function (string $key) use ($best, $contact): string {
            foreach ([$best[$key] ?? null, is_array($contact) ? ($contact[$key] ?? null) : null] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim(preg_replace('/^(?:mailto|tel):/i', '', $value) ?? $value);
                }
            }

            return '';
        };

        return array_filter([
            'name' => is_string($best['name']) ? trim($best['name']) : '',
            'telephone' => $pick('telephone'),
            'email' => $pick('email'),
            'address' => $line,
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, string> $organization
     */
    private static function frontmatter(array $meta, array $organization): string
    {
        $yaml = ['---'];
        foreach (['title', 'description', 'url', 'language', 'image', 'author', 'published', 'modified'] as $key) {
            $value = (string) ($meta[$key] ?? '');
            if ($value !== '') {
                $yaml[] = $key . ': ' . self::scalar($value);
            }
            if ($key === 'language' && $meta['alternates'] !== []) {
                $yaml[] = 'alternates:';
                foreach ($meta['alternates'] as $lang => $href) {
                    $yaml[] = '  ' . self::scalar((string) $lang) . ': ' . self::scalar($href);
                }
            }
        }
        if ($organization !== []) {
            $yaml[] = 'organization:';
            foreach ($organization as $key => $value) {
                $yaml[] = '  ' . $key . ': ' . self::scalar($value);
            }
        }
        $yaml[] = '---';

        return implode("\n", $yaml);
    }

    /** A YAML scalar: plain when that is safe, double-quoted otherwise. */
    private static function scalar(string $value): string
    {
        if (preg_match('#^[\p{L}\p{N}][\p{L}\p{N} ./_,()+-]*(?<![ :])$#u', $value) && !preg_match('/^(?:true|false|yes|no|on|off|null|~|[\d.+-]+)$/i', $value)
            && !str_contains($value, ': ') && !str_contains($value, ' #')) {
            return $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** An attribute value; '' when absent (Dom\Element answers null, DOMElement ''). */
    private static function attr(object $el, string $name): string
    {
        return (string) $el->getAttribute($name);
    }
}
