<?php

declare(strict_types=1);

namespace App\Support;

final class Pagination
{
    public const PER_PAGE = 10;

    public function __construct(private readonly int $page = 1)
    {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $value = $input['page'] ?? 1;
        $page = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        return new self(is_int($page) ? $page : 1);
    }

    public function pageForTotal(int $total): int
    {
        return min(max(1, $this->page), max(1, (int) ceil($total / self::PER_PAGE)));
    }

    public function offsetForTotal(int $total): int
    {
        return ($this->pageForTotal($total) - 1) * self::PER_PAGE;
    }
}
