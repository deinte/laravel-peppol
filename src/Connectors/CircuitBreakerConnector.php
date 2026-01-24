<?php

declare(strict_types=1);

namespace Deinte\Peppol\Connectors;

use Closure;
use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Exceptions\CircuitBreakerOpenException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Circuit breaker wrapper for PeppolConnector.
 *
 * Prevents cascading failures by blocking requests when the underlying
 * connector is failing repeatedly.
 *
 * States:
 * - CLOSED: Normal operation, requests flow through
 * - OPEN: Service failing, requests blocked for timeout period
 * - HALF_OPEN: Testing recovery, limited requests allowed
 */
class CircuitBreakerConnector implements PeppolConnector
{
    private const CACHE_PREFIX = 'peppol:circuit_breaker:';

    public function __construct(
        private readonly PeppolConnector $connector,
        private readonly int $failureThreshold = 5,
        private readonly int $timeoutSeconds = 300,
        private readonly int $successThreshold = 2,
    ) {}

    /**
     * Get the wrapped connector instance.
     * Useful for debugging/inspection without circuit breaker overhead.
     */
    public function getWrappedConnector(): PeppolConnector
    {
        return $this->connector;
    }

    public function lookupCompany(
        string $vatNumber,
        ?string $taxNumber = null,
        ?string $country = null,
        ?string $glnNumber = null,
    ): ?Company {
        return $this->execute(
            fn () => $this->connector->lookupCompany($vatNumber, $taxNumber, $country, $glnNumber),
            'lookupCompany'
        );
    }

    public function lookupCompanyByGln(string $glnNumber, string $country): ?Company
    {
        return $this->execute(
            fn () => $this->connector->lookupCompanyByGln($glnNumber, $country),
            'lookupCompanyByGln'
        );
    }

    public function sendInvoice(Invoice $invoice): InvoiceStatus
    {
        return $this->execute(
            fn () => $this->connector->sendInvoice($invoice),
            'sendInvoice'
        );
    }

    public function getInvoiceStatus(string $invoiceId): InvoiceStatus
    {
        return $this->execute(
            fn () => $this->connector->getInvoiceStatus($invoiceId),
            'getInvoiceStatus'
        );
    }

    public function getUblFile(string $invoiceId): string
    {
        return $this->execute(
            fn () => $this->connector->getUblFile($invoiceId),
            'getUblFile'
        );
    }

    public function registerCompany(Company $company): bool
    {
        return $this->execute(
            fn () => $this->connector->registerCompany($company),
            'registerCompany'
        );
    }

    public function getReceivedInvoices(string $peppolId, array $filters = []): array
    {
        return $this->execute(
            fn () => $this->connector->getReceivedInvoices($peppolId, $filters),
            'getReceivedInvoices'
        );
    }

    public function validateWebhookSignature(array $payload, string $signature): bool
    {
        // Webhooks don't go through circuit breaker
        return $this->connector->validateWebhookSignature($payload, $signature);
    }

    public function parseWebhookPayload(array $payload): array
    {
        // Webhooks don't go through circuit breaker
        return $this->connector->parseWebhookPayload($payload);
    }

    public function healthCheck(): array
    {
        // Health check doesn't go through circuit breaker - we want to know actual status
        return $this->connector->healthCheck();
    }

    /**
     * Get the current circuit breaker status.
     *
     * @return array{state: string, failure_count: int, success_count: int, last_failure: ?int, retry_after_seconds: ?int, reason: ?string}
     */
    public function getStatus(): array
    {
        $state = $this->getState();
        $failures = (int) Cache::get(self::CACHE_PREFIX.'failures', 0);
        $successes = (int) Cache::get(self::CACHE_PREFIX.'successes', 0);
        $lastFailure = Cache::get(self::CACHE_PREFIX.'last_failure');
        $reason = Cache::get(self::CACHE_PREFIX.'reason');

        $retryAfter = null;
        if ($state === 'open' && $lastFailure) {
            $elapsed = time() - $lastFailure;
            $timeout = $reason === 'rate_limit' ? 300 : $this->timeoutSeconds; // 5 min for rate limit
            $retryAfter = max(0, $timeout - $elapsed);
        }

        return [
            'state' => $state,
            'failure_count' => $failures,
            'success_count' => $successes,
            'last_failure' => $lastFailure,
            'retry_after_seconds' => $retryAfter,
            'reason' => $reason,
        ];
    }

    /**
     * Reset the circuit breaker to closed state.
     */
    public function reset(): void
    {
        Cache::forget(self::CACHE_PREFIX.'state');
        Cache::forget(self::CACHE_PREFIX.'failures');
        Cache::forget(self::CACHE_PREFIX.'successes');
        Cache::forget(self::CACHE_PREFIX.'last_failure');
        Cache::forget(self::CACHE_PREFIX.'logged_open');
        Cache::forget(self::CACHE_PREFIX.'reason');

        $this->log('info', 'Circuit breaker reset to closed state');
    }

    /**
     * Execute an operation through the circuit breaker.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function execute(Closure $operation, string $operationName): mixed
    {
        $state = $this->getState();

        // Check if circuit is open
        if ($state === 'open') {
            $lastFailure = Cache::get(self::CACHE_PREFIX.'last_failure', 0);
            $reason = Cache::get(self::CACHE_PREFIX.'reason');
            $timeout = $reason === 'rate_limit' ? 300 : $this->timeoutSeconds;
            $elapsed = time() - $lastFailure;
            $retryAfter = $timeout - $elapsed;

            if ($elapsed < $timeout) {
                // Log only once every 5 minutes to avoid flooding
                Cache::remember(self::CACHE_PREFIX.'logged_open', 300, function () use ($retryAfter, $reason) {
                    $this->log('info', 'Circuit breaker is OPEN - blocking requests', [
                        'retry_after_seconds' => $retryAfter,
                        'reason' => $reason,
                    ]);

                    return true;
                });

                throw new CircuitBreakerOpenException(
                    "Circuit breaker is open ({$reason}). Retry after {$retryAfter} seconds."
                );
            }

            // Timeout elapsed, transition to half-open
            $this->setState('half_open');
            Cache::put(self::CACHE_PREFIX.'successes', 0, now()->addHours(1));
            $this->log('info', 'Circuit breaker transitioning to HALF_OPEN', [
                'operation' => $operationName,
            ]);
        }

        try {
            $result = $operation();
            $this->recordSuccess($operationName);

            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($operationName, $e);

            throw $e;
        }
    }

    private function recordSuccess(string $operationName): void
    {
        $state = $this->getState();

        if ($state === 'half_open') {
            $successes = (int) Cache::increment(self::CACHE_PREFIX.'successes');

            if ($successes >= $this->successThreshold) {
                // Recovery confirmed, close circuit
                $this->setState('closed');
                Cache::put(self::CACHE_PREFIX.'failures', 0, now()->addHours(1));
                Cache::forget(self::CACHE_PREFIX.'successes');
                Cache::forget(self::CACHE_PREFIX.'last_failure');
                Cache::forget(self::CACHE_PREFIX.'logged_open');
                Cache::forget(self::CACHE_PREFIX.'reason');

                $this->log('info', 'Circuit breaker CLOSED - service recovered', [
                    'operation' => $operationName,
                    'successes' => $successes,
                ]);
            }
        } elseif ($state === 'closed') {
            // Reset failure count on success
            $failures = (int) Cache::get(self::CACHE_PREFIX.'failures', 0);
            if ($failures > 0) {
                Cache::decrement(self::CACHE_PREFIX.'failures');
            }
        }
    }

    private function recordFailure(string $operationName, Throwable $e): void
    {
        $state = $this->getState();
        Cache::put(self::CACHE_PREFIX.'last_failure', time(), now()->addHours(1));

        // Check for rate limit (429) - open immediately
        $isRateLimit = str_contains($e->getMessage(), '429') || str_contains($e->getMessage(), 'Rate limit');

        if ($isRateLimit) {
            $this->setState('open');
            Cache::put(self::CACHE_PREFIX.'reason', 'rate_limit', now()->addHours(1));
            Cache::forget(self::CACHE_PREFIX.'logged_open');
            $this->log('warning', 'Circuit breaker OPENED - rate limit (429)', [
                'operation' => $operationName,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($state === 'half_open') {
            // Failed during recovery test, reopen circuit
            $this->setState('open');
            $this->log('warning', 'Circuit breaker reopened during HALF_OPEN test', [
                'operation' => $operationName,
                'error' => $e->getMessage(),
            ]);
        } else {
            $failures = (int) Cache::increment(self::CACHE_PREFIX.'failures');

            if ($failures >= $this->failureThreshold) {
                $this->setState('open');
                Cache::put(self::CACHE_PREFIX.'reason', 'failures', now()->addHours(1));
                $this->log('error', 'Circuit breaker OPENED - too many failures', [
                    'operation' => $operationName,
                    'failures' => $failures,
                    'threshold' => $this->failureThreshold,
                    'error' => $e->getMessage(),
                ]);
            } else {
                $this->log('warning', 'Circuit breaker recorded failure', [
                    'operation' => $operationName,
                    'failures' => $failures,
                    'threshold' => $this->failureThreshold,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getState(): string
    {
        return Cache::get(self::CACHE_PREFIX.'state', 'closed');
    }

    private function setState(string $state): void
    {
        Cache::put(self::CACHE_PREFIX.'state', $state, now()->addHours(1));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[CircuitBreaker] {$message}", $context);
    }
}
