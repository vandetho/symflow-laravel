<?php

declare(strict_types=1);

namespace Laraflow\Data;

final readonly class GuardResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?string $code = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $reason, ?string $code = null): self
    {
        return new self(false, $reason, $code);
    }
}
