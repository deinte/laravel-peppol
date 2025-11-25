<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

/**
 * Exception thrown when webhook data is invalid.
 */
class InvalidWebhookException extends PeppolException
{
    public static function invalidSignature(): self
    {
        return new self('Invalid webhook signature');
    }

    public static function invalidPayload(string $reason): self
    {
        return new self("Invalid webhook payload: {$reason}");
    }
}
