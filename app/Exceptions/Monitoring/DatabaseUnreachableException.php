<?php

namespace App\Exceptions\Monitoring;

use Throwable;

/**
 * The application cannot reach its database.
 */
class DatabaseUnreachableException extends SystemHealthException
{
    public static function because(Throwable $previous): self
    {
        return new self('The database cannot be reached: '.$previous->getMessage(), [], $previous);
    }
}
