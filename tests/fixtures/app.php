<?php

// A tiny site behind the wrapper, for ServerTest (php -S router script).

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Digidomination\MarkdownForAgents\MarkdownForAgents;

function page(string $title): string
{
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title></head>'
        . '<body><header>Site header</header><main><h1>' . $title . '</h1><p>Body of ' . $title . '.</p></main>'
        . '<footer>Site footer</footer></body></html>';
}

(new MarkdownForAgents([
    'exclude_paths' => ['/admin'],
    'content_signal' => 'search=yes, ai-input=yes, ai-train=no',
]))->handle(static function (): void {
    switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
        case '/':
            echo page('Home');
            break;
        case '/exits':
            echo page('Exits early');
            exit;
        case '/old':
            header('Location: /new', true, 301);
            exit;
        case '/new':
            header('ETag: "v1"');
            echo page('New');
            break;
        case '/api':
            header('Content-Type: application/json');
            echo '{"ok":true}';
            break;
        case '/admin':
            echo page('Admin');
            break;
        default:
            http_response_code(404);
            echo page('Not found');
    }
});
