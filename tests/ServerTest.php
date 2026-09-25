<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End to end through PHP's built-in server: real header(), real output
 * buffers, and a page that ends in exit(), which only a shutdown flush
 * converts.
 */
final class ServerTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('proc_open')) {
            return;
        }
        self::$port = random_int(20000, 45000);
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/app.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
    }

    protected function setUp(): void
    {
        if (!is_resource(self::$process)) {
            self::markTestSkipped('Cannot start the built-in server');
        }
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string} */
    private function fetch(string $path, string $accept = 'text/html', string $method = 'GET'): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Accept: $accept\r\n",
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 5,
        ]]);
        $body = (string) @file_get_contents('http://127.0.0.1:' . self::$port . $path, false, $context);
        $raw = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []); // PHP < 8.4
        $status = (int) (explode(' ', $raw[0] ?? '')[1] ?? 0);
        $headers = [];
        foreach (array_slice($raw, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $headers[strtolower($name)][] = $value;
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    public function testNegotiatedMarkdown(): void
    {
        $r = $this->fetch('/', 'text/markdown, text/html, */*');

        self::assertSame(200, $r['status']);
        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']['content-type']);
        self::assertContains('Accept', $r['headers']['vary'] ?? []);
        self::assertSame(['search=yes, ai-input=yes, ai-train=no'], $r['headers']['content-signal']);
        self::assertArrayHasKey('x-markdown-tokens', $r['headers']);
        self::assertStringContainsString("# Home\n\nBody of Home.", $r['body']);
        self::assertStringNotContainsString('Site header', $r['body']);
    }

    public function testPageThatExitsIsStillConverted(): void
    {
        $r = $this->fetch('/exits', 'text/markdown');

        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']['content-type']);
        self::assertStringContainsString('# Exits early', $r['body']);
    }

    public function testMdUrlsAndRedirects(): void
    {
        $r = $this->fetch('/index.html.md');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('# Home', $r['body']);

        $r = $this->fetch('/old.md');
        self::assertSame(301, $r['status']);
        self::assertSame(['/new.md'], $r['headers']['location']);

        $r = $this->fetch('/new.md');
        self::assertSame(200, $r['status']);
        self::assertArrayNotHasKey('etag', $r['headers']);
        self::assertSame(['<http://127.0.0.1:' . self::$port . '/new>; rel="canonical"'], $r['headers']['link']);

        $r = $this->fetch('/missing.md');
        self::assertSame(404, $r['status']);
        self::assertStringContainsString('# Not found', $r['body']);
    }

    public function testHtmlStaysHtmlAndPointsAtMarkdown(): void
    {
        $r = $this->fetch('/new');

        self::assertSame(200, $r['status']);
        self::assertStringStartsWith('text/html', $r['headers']['content-type'][0]);
        self::assertSame(['</new.md>; rel="alternate"; type="text/markdown"'], $r['headers']['link']);
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="/new.md">', $r['body']);
    }

    public function testJsonAndExcludedPathsAreUntouched(): void
    {
        $r = $this->fetch('/api', 'text/markdown, */*');
        self::assertSame('{"ok":true}', $r['body']);
        self::assertSame(['application/json'], $r['headers']['content-type']);

        $r = $this->fetch('/admin', 'text/markdown');
        self::assertStringStartsWith('<!DOCTYPE html>', $r['body']);
        self::assertArrayNotHasKey('link', $r['headers']);
    }

    public function testHead(): void
    {
        $r = $this->fetch('/', 'text/markdown', 'HEAD');

        self::assertSame(200, $r['status']);
        self::assertSame(['text/markdown; charset=utf-8'], $r['headers']['content-type']);
        self::assertSame('', $r['body']);
    }
}
