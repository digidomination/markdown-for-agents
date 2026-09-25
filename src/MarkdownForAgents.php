<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * Front-controller wrapper that serves a Markdown version of every HTML page.
 *
 *     $agents = new MarkdownForAgents(['content_signal' => 'search=yes, ai-input=yes, ai-train=no']);
 *     $agents->handle(fn () => $app->run());
 *
 * A request gets Markdown when it sends "Accept: text/markdown" (ranked at
 * least as high as text/html) or asks for the page URL with ".md" appended.
 * The app runs exactly as it always does and renders its HTML; the wrapper
 * converts that output on the way out. Everything else passes through, and
 * HTML pages gain "Vary: Accept" plus a Link header (and <link> tag) that
 * point agents at the Markdown version.
 */
final class MarkdownForAgents
{
    public const DEFAULTS = [
        // Serve Markdown for "Accept: text/markdown".
        'negotiate' => true,
        // Serve Markdown at the page URL + ".md" ("index.html.md" after a slash).
        'suffix' => true,
        // On HTML pages: "Vary: Accept" and a Link header naming the Markdown URL.
        'advertise' => true,
        // Also put <link rel="alternate" type="text/markdown"> into the <head>.
        'link_tag' => true,
        // Path prefixes that never get Markdown ("/admin" covers "/admin" and "/admin/…").
        'exclude_paths' => [],
        // Content-Signal header for Markdown responses when the app sets none, e.g.
        // "search=yes, ai-input=yes, ai-train=no". null sends nothing.
        'content_signal' => null,
        // Where the content is: the first selector that matches.
        'main' => ['main', '[role=main]', 'article', 'body'],
        // Extra selectors to drop inside the content root.
        'exclude' => [],
        // Images: 'alt' (only those with alt text), 'all' or 'none'.
        'images' => 'alt',
        'frontmatter' => true,
        // Append the page's JSON-LD as a fenced block. Off by default: on a
        // schema-rich page it repeats the content and can triple the size.
        'json_ld' => false,
        // Put the business's name, phone, email and address (from its JSON-LD)
        // into the frontmatter; the footer that shows them is stripped.
        'organization' => true,
        // Pages larger than this are passed through as HTML.
        'max_bytes' => 2_097_152,
        // 'auto' uses PHP 8.4's HTML5 parser when present, else libxml.
        'parser' => Html::PARSER_AUTO,
        // Optional JSONL file: one line per Markdown response (path, mode, agent, tokens; no IP).
        'log' => null,
    ];

    private const MODE_ACCEPT = 'accept';
    private const MODE_SUFFIX = 'suffix';
    private const LOG_ROTATE_BYTES = 5_242_880;

    /** @var array<string, mixed> */
    private array $options;
    private Headers $headers;
    /** Set when the app threw: output then passes through untouched. */
    private bool $bypass = false;

    /** @param array<string, mixed> $options see DEFAULTS */
    public function __construct(array $options = [], ?Headers $headers = null)
    {
        $unknown = array_diff_key($options, self::DEFAULTS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown option(s): ' . implode(', ', array_keys($unknown)));
        }
        $this->options = $options + self::DEFAULTS;
        $this->headers = $headers ?? new NativeHeaders();
    }

    /**
     * Runs $app. For a Markdown request the app sees the page's own URL, so
     * routing, redirects and 404s behave exactly as they do for HTML.
     */
    public function handle(callable $app): void
    {
        $this->bypass = false;
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = $path === '' ? '/' : $path;
        $query = parse_url($uri, PHP_URL_QUERY);

        if ($method !== 'GET' && $method !== 'HEAD') {
            $app();

            return;
        }

        $mode = null;
        $pagePath = $path;
        $htmlPath = Negotiator::htmlPath($path);
        if ($this->options['suffix'] && $htmlPath !== null && $this->eligible($htmlPath)) {
            $mode = self::MODE_SUFFIX;
            $pagePath = $htmlPath;
            $_SERVER['REQUEST_URI'] = $htmlPath . (is_string($query) && $query !== '' ? '?' . $query : '');
            $_SERVER['MARKDOWN_FOR_AGENTS_URI'] = $uri;
        } elseif ($this->options['negotiate'] && $htmlPath === null && $this->eligible($path)
            && Negotiator::prefersMarkdown(isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : null)) {
            $mode = self::MODE_ACCEPT;
        }

        if ($mode === null) {
            if (!$this->options['advertise'] || $htmlPath !== null || !$this->eligible($path)) {
                $app();

                return;
            }
            $finish = fn (string $body): string => $this->advertise($body, $path);
        } else {
            $finish = fn (string $body): string => $this->markdown($body, $mode, $pagePath, $path);
        }

        $level = $this->capture($finish);
        try {
            $app();
        } catch (\Throwable $e) {
            // Let PHP report the failure exactly as it would without us.
            $this->bypass = true;
            throw $e;
        }
        while (ob_get_level() > $level) {
            ob_end_flush();
        }
        if (ob_get_level() === $level) {
            ob_end_flush();
        }
    }

    /**
     * Buffers everything the app prints and hands it to $finish once, at the
     * end. A callback buffer rather than ob_get_clean() so a page that ends
     * in exit() is still converted: PHP flushes the buffer on shutdown.
     */
    private function capture(\Closure $finish): int
    {
        $buffer = '';
        ob_start(function (string $chunk, int $phase) use (&$buffer, $finish): string {
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                $buffer = '';

                return '';
            }
            $buffer .= $chunk;
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) === 0) {
                return '';
            }
            $body = $buffer;
            $buffer = '';
            if ($this->bypass) {
                return $body;
            }
            try {
                return $finish($body);
            } catch (\Throwable) {
                return $body;
            }
        });

        return ob_get_level();
    }

    private function markdown(string $body, string $mode, string $pagePath, string $requestPath): string
    {
        if ($this->headers->sent()) {
            return $body;
        }
        $status = $this->headers->status();

        if ($status >= 300 && $status < 400) {
            if ($mode === self::MODE_SUFFIX) {
                $this->followWithMarkdown();
            } else {
                $this->headers->add('Vary', 'Accept');
            }

            return $body;
        }

        $type = strtolower($this->header('Content-Type') ?? (ini_get('default_mimetype') ?: 'text/html'));
        if (!str_starts_with($type, 'text/html') || strlen($body) > (int) $this->options['max_bytes']) {
            if ($mode === self::MODE_ACCEPT) {
                // Not a page (JSON, XML, a download): nothing to convert.
                return $body;
            }
            $this->headers->setStatus(404);
            $this->markdownHeaders(null);

            return "# Not found\n\nThere is no Markdown version of this URL.\n";
        }

        $page = (new PageConverter($this->options))->convert($body, $this->pageUrl($pagePath));
        $markdown = $page['markdown'];

        $this->markdownHeaders($mode === self::MODE_SUFFIX ? (string) $page['meta']['url'] : null);
        if ($mode === self::MODE_ACCEPT) {
            $this->headers->add('Vary', 'Accept');
        }
        $tokens = Tokens::estimate($markdown);
        $original = Tokens::estimate($body);
        $this->headers->set('X-Markdown-Tokens', (string) $tokens);
        $this->headers->set('X-Original-Tokens', (string) $original);
        if (is_string($this->options['content_signal']) && $this->options['content_signal'] !== ''
            && $this->header('Content-Signal') === null) {
            $this->headers->set('Content-Signal', $this->options['content_signal']);
        }

        $this->log($requestPath, $mode, $status, $tokens, $original);

        return $markdown;
    }

    /** @param ?string $canonical the HTML page, for a response served at a .md URL */
    private function markdownHeaders(?string $canonical): void
    {
        $this->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        // A converted body cannot honour the HTML's validators or length.
        foreach (['Content-Length', 'ETag', 'Last-Modified'] as $name) {
            $this->headers->remove($name);
        }
        if ($canonical !== null && $canonical !== '') {
            $this->headers->add('Link', '<' . $canonical . '>; rel="canonical"');
        }
    }

    /** A redirect answering a .md URL points at the target's .md URL. */
    private function followWithMarkdown(): void
    {
        $location = $this->header('Location');
        if ($location === null) {
            return;
        }
        $parts = parse_url($location);
        if ($parts === false) {
            return;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ownHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== '' && $host !== $ownHost) {
            return;
        }
        $path = (string) ($parts['path'] ?? '/');
        if (!$this->eligible($path) || Negotiator::htmlPath($path) !== null) {
            return;
        }
        $prefix = $host !== '' ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '';
        $this->headers->set(
            'Location',
            $prefix . Negotiator::markdownPath($path)
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : ''),
        );
    }

    private function advertise(string $body, string $path): string
    {
        if ($this->headers->sent() || $this->headers->status() !== 200) {
            return $body;
        }
        $type = strtolower($this->header('Content-Type') ?? (ini_get('default_mimetype') ?: 'text/html'));
        if (!str_starts_with($type, 'text/html')) {
            return $body;
        }
        $this->headers->add('Vary', 'Accept');
        // A noindex page stays out of every index, including agents' reading lists.
        if (preg_match('/<meta[^>]+name=["\']?robots["\']?[^>]*noindex/i', $body)
            || preg_match('/<meta[^>]+content=["\'][^"\']*noindex[^>]*name=["\']?robots/i', $body)) {
            return $body;
        }
        $markdownPath = Negotiator::markdownPath($path);
        $this->headers->add('Link', '<' . $markdownPath . '>; rel="alternate"; type="text/markdown"');
        if ($this->options['link_tag'] && !str_contains($body, 'type="text/markdown"')) {
            $tag = '<link rel="alternate" type="text/markdown" href="' . htmlspecialchars($markdownPath, ENT_QUOTES) . '">';
            $body = preg_replace('~</head>~i', $tag . "\n</head>", $body, 1) ?? $body;
        }

        return $body;
    }

    private function eligible(string $path): bool
    {
        foreach ((array) $this->options['exclude_paths'] as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix === '') {
                continue;
            }
            if (str_ends_with($prefix, '/')
                ? str_starts_with($path, $prefix)
                : ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return false;
            }
        }

        return true;
    }

    /** The absolute page URL, for pages that declare no canonical. */
    private function pageUrl(string $path): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return ($https ? 'https' : 'http') . '://' . $host . $path;
    }

    private function header(string $name): ?string
    {
        $value = null;
        foreach ($this->headers->all() as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                $value = trim($parts[1]);
            }
        }

        return $value;
    }

    private function log(string $path, string $mode, int $status, int $tokens, int $original): void
    {
        $file = $this->options['log'];
        if (!is_string($file) || $file === '') {
            return;
        }
        $line = json_encode([
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'path' => $path,
            'mode' => $mode,
            'status' => $status,
            'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            'tokens' => $tokens,
            'html_tokens' => $original,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) {
            return;
        }
        // Never let bookkeeping break the response.
        try {
            if (is_file($file) && (int) @filesize($file) > self::LOG_ROTATE_BYTES) {
                @rename($file, $file . '.1');
            }
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }
}
