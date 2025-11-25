<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

/**
 * Exception thrown when company registration fails.
 */
class RegistrationException extends PeppolException
{
    public static function alreadyRegistered(string $vatNumber): self
    {
        return new self("Company already registered on PEPPOL: {$vatNumber}");
    }

    public static function invalidData(string $reason): self
    {
        return new self("Invalid registration data: {$reason}");
    }
}
