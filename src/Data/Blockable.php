<?php

declare(strict_types=1);

namespace Laraflow\Data;

trait Blockable
{
    private bool $blocked = false;

    private ?string $blockedReason = null;

    private ?string $blockedCode = null;

    public function block(string $reason, ?string $code = null): void
    {
        $this->blocked = true;
        $this->blockedReason = $reason;
        $this->blockedCode = $code;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function getBlockedCode(): ?string
    {
        return $this->blockedCode;
    }
}
