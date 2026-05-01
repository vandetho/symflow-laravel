<?php

declare(strict_types=1);

namespace Laraflow\Exceptions;

use RuntimeException;
use Throwable;

class ListenerExceptionAggregate extends RuntimeException
{
    /**
     * @param  array<Throwable>  $exceptions
     */
    public function __construct(
        private readonly array $exceptions,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : $this->buildMessage($exceptions),
            previous: $exceptions[0] ?? null,
        );
    }

    /**
     * @return array<Throwable>
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    /**
     * @param  array<Throwable>  $exceptions
     */
    private function buildMessage(array $exceptions): string
    {
        $count = count($exceptions);
        $first = $exceptions[0] ?? null;
        $firstMsg = $first !== null ? $first->getMessage() : '';

        return $count === 1
            ? "1 listener threw: {$firstMsg}"
            : "{$count} listeners threw (first: {$firstMsg})";
    }
}
