<?php

declare(strict_types=1);

namespace Deinte\Peppol\Exceptions;

/**
 * Thrown when the circuit breaker is open and blocking requests.
 */
class CircuitBreakerOpenException extends PeppolException
{
    public function __construct(string $message = 'Circuit breaker is open')
    {
        parent::__construct($message);
        $this->setContext([
            'circuit_breaker' => 'open',
        ]);
    }
}
