<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

/**
 * Exception thrown when an invoice cannot be found.
 */
class InvoiceNotFoundException extends PeppolException
{
    public static function withId(string $invoiceId): self
    {
        return new self("Invoice not found with ID: {$invoiceId}");
    }
}
