<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/** Headers of the current PHP response, through header() and friends. */
final class NativeHeaders implements Headers
{
    public function all(): array
    {
        return headers_list();
    }

    public function set(string $name, string $value): void
    {
        header($name . ': ' . $value, true);
    }

    public function add(string $name, string $value): void
    {
        header($name . ': ' . $value, false);
    }

    public function remove(string $name): void
    {
        header_remove($name);
    }

    public function status(): int
    {
        $status = http_response_code();

        return is_int($status) ? $status : 200;
    }

    public function setStatus(int $status): void
    {
        http_response_code($status);
    }

    public function sent(): bool
    {
        return headers_sent();
    }
}
