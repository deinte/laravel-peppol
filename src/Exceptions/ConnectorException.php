<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

/**
 * Exception thrown when a connector encounters an error.
 */
class ConnectorException extends PeppolException
{
    public static function connectionFailed(string $message): self
    {
        return new self("Connector connection failed: {$message}");
    }

    public static function apiError(string $message, int $statusCode): self
    {
        return new self("Connector API error ({$statusCode}): {$message}");
    }
}
