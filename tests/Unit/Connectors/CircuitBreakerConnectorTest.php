<?php

declare(strict_types=1);

use Deinte\Peppol\Connectors\CircuitBreakerConnector;
use Deinte\Peppol\Contracts\PeppolConnector;

describe('CircuitBreakerConnector', function () {
    describe('getWrappedConnector()', function () {
        it('returns the wrapped connector instance', function () {
            $mockConnector = Mockery::mock(PeppolConnector::class);

            $circuitBreaker = new CircuitBreakerConnector(
                connector: $mockConnector,
                failureThreshold: 5,
                timeoutSeconds: 300,
                successThreshold: 2,
            );

            $wrapped = $circuitBreaker->getWrappedConnector();

            expect($wrapped)->toBe($mockConnector);
        });

        it('returns the same instance on multiple calls', function () {
            $mockConnector = Mockery::mock(PeppolConnector::class);

            $circuitBreaker = new CircuitBreakerConnector(
                connector: $mockConnector,
            );

            $first = $circuitBreaker->getWrappedConnector();
            $second = $circuitBreaker->getWrappedConnector();

            expect($first)->toBe($second);
        });
    });
});
