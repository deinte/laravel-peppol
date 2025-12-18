<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

use Exception;

/**
 * Base exception for all PEPPOL package errors.
 */
class PeppolException extends Exception
{
    /**
     * Additional context data for the exception.
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * Set exception context data.
     *
     * @param  array<string, mixed>  $context
     */
    public function setContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Get exception context data.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get a specific context value.
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}
