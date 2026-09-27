<?php

declare(strict_types=1);

namespace ClickClacks\Transport;

/** A network error or a timeout: no HTTP response arrived. Always retried. */
final class TransportException extends \RuntimeException {}
