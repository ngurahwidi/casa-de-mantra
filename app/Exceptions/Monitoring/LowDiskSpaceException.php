<?php

namespace App\Exceptions\Monitoring;

/**
 * The disk holding storage/ is nearly full. Uploads, logs and local backups would start failing.
 */
class LowDiskSpaceException extends SystemHealthException
{
    public static function at(string $path, float $freePercent, int $minimumPercent): self
    {
        return new self(
            sprintf('Only %.1f%% of the disk holding %s is free (minimum %d%%).', $freePercent, $path, $minimumPercent),
            ['path' => $path, 'free_percent' => round($freePercent, 1), 'minimum_percent' => $minimumPercent],
        );
    }

    public static function unreadable(string $path): self
    {
        return new self("The free space of the disk holding {$path} could not be read.", ['path' => $path]);
    }
}
