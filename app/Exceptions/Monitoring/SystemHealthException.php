<?php

namespace App\Exceptions\Monitoring;

use RuntimeException;

/**
 * A problem with the server or the background processes rather than with a request (T12.4).
 *
 * These are never thrown at guests. They are passed to report(), which writes them to the log and
 * sends them to Sentry. Each subclass becomes its own Sentry issue, so an alert rule on new issues
 * tells the developer about each kind of problem once, and again only if it comes back after being
 * resolved.
 */
abstract class SystemHealthException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    final public function __construct(string $message, private readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Extra log context, picked up by Laravel's exception handler.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->details;
    }
}
