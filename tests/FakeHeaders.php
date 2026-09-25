<?php

declare(strict_types=1);

namespace Digidomination\MarkdownForAgents\Tests;

use Digidomination\MarkdownForAgents\Headers;

/** Records headers the way PHP would send them. */
final class FakeHeaders implements Headers
{
    /** @var list<array{string, string}> */
    public array $lines = [];
    public int $code = 200;

    public function all(): array
    {
        return array_map(static fn (array $l): string => $l[0] . ': ' . $l[1], $this->lines);
    }

    public function set(string $name, string $value): void
    {
        $this->remove($name);
        $this->lines[] = [$name, $value];
    }

    public function add(string $name, string $value): void
    {
        $this->lines[] = [$name, $value];
    }

    public function remove(string $name): void
    {
        $this->lines = array_values(array_filter($this->lines, static fn (array $l): bool => strcasecmp($l[0], $name) !== 0));
    }

    public function status(): int
    {
        return $this->code;
    }

    public function setStatus(int $status): void
    {
        $this->code = $status;
    }

    public function sent(): bool
    {
        return false;
    }

    /** @return list<string> every value sent under $name */
    public function get(string $name): array
    {
        return array_values(array_map(
            static fn (array $l): string => $l[1],
            array_filter($this->lines, static fn (array $l): bool => strcasecmp($l[0], $name) === 0),
        ));
    }
}
