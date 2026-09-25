<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents;

/**
 * The response headers of the running request. NativeHeaders wraps PHP's
 * header functions; tests pass a recorder instead.
 */
interface Headers
{
    /** Header lines as they will be sent, "Name: value". */
    public function all(): array;

    public function set(string $name, string $value): void;

    /** Adds a line without replacing others of the same name (Vary, Link). */
    public function add(string $name, string $value): void;

    public function remove(string $name): void;

    /** The status code the response will carry. */
    public function status(): int;

    public function setStatus(int $status): void;

    /** True once headers have gone out and can no longer change. */
    public function sent(): bool;
}
