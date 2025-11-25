<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

/**
 * Exception thrown when invoice data is invalid.
 */
class InvalidInvoiceException extends PeppolException
{
    public static function missingRequired(string $field): self
    {
        return new self("Required invoice field missing: {$field}");
    }

    public static function invalidFormat(string $field, string $reason): self
    {
        return new self("Invalid invoice field '{$field}': {$reason}");
    }
}
