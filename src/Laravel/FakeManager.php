<?php

declare(strict_types=1);

namespace ClickClacks\Laravel;

use ClickClacks\ClickClacksInterface;
use ClickClacks\Testing\FakeClient;

/**
 * `ClickClacks::fake()`: records every call, including ones made through `queue()` and
 * `now()`, and sends nothing.
 */
class FakeManager extends FakeClient
{
    public function queue(): ClickClacksInterface
    {
        return $this;
    }

    public function now(): ClickClacksInterface
    {
        return $this;
    }
}
