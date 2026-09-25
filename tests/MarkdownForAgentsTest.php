<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use Digidomination\MarkdownForAgents\MarkdownForAgents;
use PHPUnit\Framework\TestCase;

final class MarkdownForAgentsTest extends TestCase
{
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
    }

    private static function page(string $title = 'Pricing', string $head = ''): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '<link rel="canonical" href="https://example.com/pricing">' . $head . '</head><body><header>Site header</header>'
            . '<main><h1>' . $title . '</h1><p>Plans start at 29 €.</p></main><footer>Site footer</footer></body></html>';
    }

    /**
     * Runs the wrapper around $app for one request.
     *
     * @param array<string, string> $server
     * @return array{body: string, headers: FakeHeaders, uri: string}
     */
    private function request(array $server, ?\Closure $app = null, array $options = []): array
    {
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/pricing', 'HTTP_HOST' => 'example.com', 'HTTPS' => 'on'];
        $headers = new FakeHeaders();
        $seen = '';
        $app ??= static function (): void {
            echo self::page();
        };
        ob_start();
        (new MarkdownForAgents($options, $headers))->handle(function () use ($app, $headers, &$seen): void {
            $seen = $_SERVER['REQUEST_URI'];
            $app($headers);
        });

        return ['body' => (string) ob_get_clean(), 'headers' => $headers, 'uri' => $seen];
    }

    public function testAcceptHeaderGetsMarkdown(): void
    {
        $r = $this->request(['HTTP_ACCEPT' => 'text/markdown, text/html;q=0.9'], null, ['content_signal' => 'search=yes, ai-input=yes, ai-train=no']);

        self::assertStringStartsWith("---\ntitle: Pricing\nurl: \"https://example.com/pricing\"\nlanguage: en\n---\n\n# Pricing\n\nPlans start at 29 €.\n", $r['body']);
        self::assertStringNotContainsString('Site header', $r['body']);
        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']->get('Content-Type'));
        self::assertSame(['Accept'], $r['headers']->get('Vary'));
        self::assertSame(['search=yes, ai-input=yes, ai-train=no'], $r['headers']->get('Content-Signal'));
        self::assertMatchesRegularExpression('/^\d+$/', $r['headers']->get('X-Markdown-Tokens')[0]);
        self::assertGreaterThan((int) $r['headers']->get('X-Markdown-Tokens')[0], (int) $r['headers']->get('X-Original-Tokens')[0]);
        self::assertSame('/pricing', $r['uri']);
    }

    public function testTheAppsOwnContentSignalWins(): void
    {
        $r = $this->request(['HTTP_ACCEPT' => 'text/markdown'], static function (FakeHeaders $h): void {
            $h->set('Content-Signal', 'search=yes,ai-train=no');
            echo self::page();
        }, ['content_signal' => 'search=yes, ai-train=yes']);

        self::assertSame(['search=yes,ai-train=no'], $r['headers']->get('Content-Signal'));
    }

    public function testConvertedBodyDropsValidators(): void
    {
        $r = $this->request(['HTTP_ACCEPT' => 'text/markdown'], static function (FakeHeaders $h): void {
            $h->set('ETag', '"abc"');
            $h->set('Last-Modified', 'Mon, 01 Sep 2026 10:00:00 GMT');
            $h->set('Content-Length', '999');
            $h->set('Cache-Control', 'public, max-age=60');
            echo self::page();
        });

        self::assertSame([], $r['headers']->get('ETag'));
        self::assertSame([], $r['headers']->get('Last-Modified'));
        self::assertSame([], $r['headers']->get('Content-Length'));
        self::assertSame(['public, max-age=60'], $r['headers']->get('Cache-Control'));
    }

    public function testMdSuffixServesThePageBehindIt(): void
    {
        $r = $this->request(['REQUEST_URI' => '/pricing.md?ref=x']);

        self::assertSame('/pricing?ref=x', $r['uri']);
        self::assertStringContainsString("# Pricing\n", $r['body']);
        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']->get('Content-Type'));
        self::assertSame(['<https://example.com/pricing>; rel="canonical"'], $r['headers']->get('Link'));
        self::assertSame([], $r['headers']->get('Vary'));
    }

    public function testIndexMarkdownPath(): void
    {
        $r = $this->request(['REQUEST_URI' => '/en/index.html.md']);

        self::assertSame('/en/', $r['uri']);
    }

    public function testBrowsersGetHtmlThatAdvertisesTheMarkdown(): void
    {
        $r = $this->request(['HTTP_ACCEPT' => 'text/html,*/*;q=0.8']);

        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="/pricing.md">' . "\n</head>", $r['body']);
        self::assertSame(['Accept'], $r['headers']->get('Vary'));
        self::assertSame(['</pricing.md>; rel="alternate"; type="text/markdown"'], $r['headers']->get('Link'));
        self::assertSame([], $r['headers']->get('Content-Type'));
    }

    public function testTrailingSlashPagesAdvertiseIndexMarkdown(): void
    {
        $r = $this->request(['REQUEST_URI' => '/en/'], null, ['link_tag' => false]);

        self::assertSame(['</en/index.html.md>; rel="alternate"; type="text/markdown"'], $r['headers']->get('Link'));
        self::assertStringNotContainsString('text/markdown', $r['body']);
    }

    public function testNoindexPagesAreNotAdvertised(): void
    {
        $r = $this->request([], static function (): void {
            echo self::page('Thanks', '<meta name="robots" content="noindex">');
        });

        self::assertSame([], $r['headers']->get('Link'));
        self::assertStringNotContainsString('text/markdown', $r['body']);
        self::assertSame(['Accept'], $r['headers']->get('Vary'));
    }

    public function testExcludedPathsAreLeftAlone(): void
    {
        $options = ['exclude_paths' => ['/admin', '/intake/']];

        $r = $this->request(['REQUEST_URI' => '/admin/login', 'HTTP_ACCEPT' => 'text/markdown'], null, $options);
        self::assertStringStartsWith('<!DOCTYPE html>', $r['body']);
        self::assertSame([], $r['headers']->lines);

        $r = $this->request(['REQUEST_URI' => '/admin.md'], null, $options);
        self::assertSame('/admin.md', $r['uri']);
        self::assertStringStartsWith('<!DOCTYPE html>', $r['body']);

        // "/admin" does not swallow "/administration".
        $r = $this->request(['REQUEST_URI' => '/administration', 'HTTP_ACCEPT' => 'text/markdown'], null, $options);
        self::assertStringStartsWith('---', $r['body']);
    }

    public function testOnlyGetAndHead(): void
    {
        $r = $this->request(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'text/markdown']);
        self::assertStringStartsWith('<!DOCTYPE html>', $r['body']);
        self::assertSame([], $r['headers']->lines);

        $r = $this->request(['REQUEST_METHOD' => 'HEAD', 'HTTP_ACCEPT' => 'text/markdown']);
        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']->get('Content-Type'));
    }

    public function testNonHtmlPassesThroughUnderNegotiation(): void
    {
        $r = $this->request(['REQUEST_URI' => '/api', 'HTTP_ACCEPT' => 'text/markdown, */*'], static function (FakeHeaders $h): void {
            $h->set('Content-Type', 'application/json');
            echo '{"ok":true}';
        });

        self::assertSame('{"ok":true}', $r['body']);
        self::assertSame(['application/json'], $r['headers']->get('Content-Type'));
    }

    public function testNonHtmlAtAnMdUrlIsNotFound(): void
    {
        $r = $this->request(['REQUEST_URI' => '/feed.xml.md'], static function (FakeHeaders $h): void {
            $h->set('Content-Type', 'application/rss+xml');
            echo '<rss/>';
        });

        self::assertSame(404, $r['headers']->code);
        self::assertStringStartsWith('# Not found', $r['body']);
        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']->get('Content-Type'));
    }

    public function testRedirectsFromMdUrlsStayMarkdown(): void
    {
        $redirect = static fn (string $to): \Closure => static function (FakeHeaders $h) use ($to): void {
            $h->setStatus(301);
            $h->set('Location', $to);
        };

        $r = $this->request(['REQUEST_URI' => '/old.md'], $redirect('/new?x=1#top'));
        self::assertSame(['/new.md?x=1#top'], $r['headers']->get('Location'));

        $r = $this->request(['REQUEST_URI' => '/docs.md'], $redirect('https://example.com/docs/'));
        self::assertSame(['https://example.com/docs/index.html.md'], $r['headers']->get('Location'));

        $r = $this->request(['REQUEST_URI' => '/away.md'], $redirect('https://elsewhere.example/page'));
        self::assertSame(['https://elsewhere.example/page'], $r['headers']->get('Location'));

        $r = $this->request(['REQUEST_URI' => '/old', 'HTTP_ACCEPT' => 'text/markdown'], $redirect('/new'));
        self::assertSame(['/new'], $r['headers']->get('Location'));
        self::assertSame(['Accept'], $r['headers']->get('Vary'));
    }

    public function testNotFoundPagesConvertWithTheirStatus(): void
    {
        $r = $this->request(['REQUEST_URI' => '/nope.md'], static function (FakeHeaders $h): void {
            $h->setStatus(404);
            echo self::page('Page not found');
        });

        self::assertSame(404, $r['headers']->code);
        self::assertStringContainsString('# Page not found', $r['body']);
    }

    public function testCleanedOutputIsDiscarded(): void
    {
        $r = $this->request(['HTTP_ACCEPT' => 'text/markdown'], static function (): void {
            echo 'Warning: junk';
            ob_clean();
            echo self::page();
        });

        self::assertStringNotContainsString('junk', $r['body']);
        self::assertStringContainsString('# Pricing', $r['body']);
    }

    public function testAppFailuresPassThroughUntouched(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/pricing', 'HTTP_ACCEPT' => 'text/markdown', 'HTTP_HOST' => 'example.com'];
        $headers = new FakeHeaders();
        ob_start();
        $level = ob_get_level();
        try {
            (new MarkdownForAgents([], $headers))->handle(static function (): void {
                echo '<html><body><main>partial';
                throw new \RuntimeException('boom');
            });
            self::fail('The exception must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        // PHP flushes the wrapper's buffer on shutdown; do it here.
        while (ob_get_level() > $level) {
            ob_end_flush();
        }
        self::assertSame('<html><body><main>partial', ob_get_clean());
        self::assertSame([], $headers->lines);
    }

    public function testLogLine(): void
    {
        $file = sys_get_temp_dir() . '/mfa-log-' . bin2hex(random_bytes(4)) . '.jsonl';
        try {
            $this->request(['REQUEST_URI' => '/pricing.md', 'HTTP_USER_AGENT' => 'Claude-User/1.0', 'REMOTE_ADDR' => '203.0.113.9'], null, ['log' => $file]);
            $line = json_decode(trim((string) file_get_contents($file)), true);

            self::assertSame('/pricing.md', $line['path']);
            self::assertSame('suffix', $line['mode']);
            self::assertSame(200, $line['status']);
            self::assertSame('Claude-User/1.0', $line['agent']);
            self::assertGreaterThan(0, $line['tokens']);
            self::assertStringNotContainsString('203.0.113.9', (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testUnknownOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MarkdownForAgents(['jsonld' => true]);
    }
}
