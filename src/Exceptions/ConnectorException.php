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

    /**
     * Create an API error exception with structured response data.
     *
     * @param  string  $message  Human-readable error message
     * @param  int  $statusCode  HTTP status code
     * @param  array<string, mixed>|null  $responseData  Structured API response data
     */
    public static function apiError(string $message, int $statusCode, ?array $responseData = null): self
    {
        $exception = new self("Connector API error ({$statusCode}): {$message}");

        if ($responseData !== null) {
            $exception->setContext([
                'status_code' => $statusCode,
                'response_data' => $responseData,
            ]);
        }

        return $exception;
    }
}
