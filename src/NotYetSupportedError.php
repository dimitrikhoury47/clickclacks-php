<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * Thrown by reserved methods until the API supports them. No method throws it in 1.0.0
 * (`group()` is supported); it stays for `alias`, which is coming.
 */
final class NotYetSupportedError extends \LogicException
{
    public function __construct(public readonly string $method, string $message)
    {
        parent::__construct($message);
    }
}
