<?php

declare(strict_types=1);

namespace Deinte\Peppol\Enums;

/**
 * Single state machine for PEPPOL invoice lifecycle.
 *
 * Replaces the old dual-status system (connector_status + status).
 * One enum, clear progression, no confusion.
 *
 * Flow:
 * scheduled → sending → sent → polling → delivered/accepted
 *                ↓                           ↓
 *           send_failed                  rejected
 *                ↓
 *             failed
 */
enum PeppolState: string
{
    // === Dispatch Phase ===

    /**
     * Invoice is scheduled, waiting for scheduled_at time.
     */
    case SCHEDULED = 'scheduled';

    /**
     * API call to connector is in progress.
     */
    case SENDING = 'sending';

    /**
     * API call failed, will retry (dispatch_attempts < max).
     */
    case SEND_FAILED = 'send_failed';

    /**
     * Successfully uploaded to connector (has connector_invoice_id).
     */
    case SENT = 'sent';

    // === Delivery Phase ===

    /**
     * Polling connector for PEPPOL delivery status.
     */
    case POLLING = 'polling';

    /**
     * PEPPOL confirmed delivery to recipient's access point.
     */
    case DELIVERED = 'delivered';

    /**
     * Recipient explicitly accepted the invoice.
     */
    case ACCEPTED = 'accepted';

    /**
     * Recipient explicitly rejected the invoice.
     */
    case REJECTED = 'rejected';

    // === Terminal States ===

    /**
     * Max retries exceeded, permanent failure.
     */
    case FAILED = 'failed';

    /**
     * User manually cancelled the invoice.
     */
    case CANCELLED = 'cancelled';

    /**
     * Stored at connector but skip_delivery=true (not sent via PEPPOL).
     */
    case STORED = 'stored';

    /**
     * Can this invoice be retried for dispatch?
     */
    public function canRetryDispatch(): bool
    {
        return $this === self::SEND_FAILED;
    }

    /**
     * Should we poll for delivery status?
     */
    public function shouldPoll(): bool
    {
        return in_array($this, [
            self::SENT,
            self::POLLING,
        ], true);
    }

    /**
     * Is this a final state (no more processing)?
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::ACCEPTED,
            self::REJECTED,
            self::FAILED,
            self::CANCELLED,
            self::STORED,
        ], true);
    }

    /**
     * Is this a successful terminal state?
     */
    public function isSuccess(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::ACCEPTED,
            self::STORED,
        ], true);
    }

    /**
     * Is this a failure state (terminal or retryable)?
     */
    public function isFailure(): bool
    {
        return in_array($this, [
            self::SEND_FAILED,
            self::REJECTED,
            self::FAILED,
        ], true);
    }

    /**
     * Is the invoice waiting to be dispatched?
     */
    public function isPendingDispatch(): bool
    {
        return in_array($this, [
            self::SCHEDULED,
            self::SEND_FAILED,
        ], true);
    }

    /**
     * Is the invoice currently being processed?
     */
    public function isInProgress(): bool
    {
        return in_array($this, [
            self::SENDING,
            self::SENT,
            self::POLLING,
        ], true);
    }

    /**
     * Can this invoice be rescheduled?
     *
     * Only allows rescheduling for invoices that haven't been sent yet
     * or failed during the upload phase (before reaching PEPPOL).
     */
    public function canReschedule(): bool
    {
        return in_array($this, [
            self::SCHEDULED,
            self::SEND_FAILED,
        ], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Scheduled',
            self::SENDING => 'Sending',
            self::SEND_FAILED => 'Send Failed',
            self::SENT => 'Sent',
            self::POLLING => 'Polling',
            self::DELIVERED => 'Delivered',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::STORED => 'Stored',
        };
    }

    /**
     * Can transition to the given state?
     */
    public function canTransitionTo(self $newState): bool
    {
        return match ($this) {
            self::SCHEDULED => in_array($newState, [self::SENDING, self::CANCELLED], true),
            self::SENDING => in_array($newState, [self::SENT, self::SEND_FAILED, self::STORED], true),
            self::SEND_FAILED => in_array($newState, [self::SENDING, self::FAILED, self::CANCELLED], true),
            self::SENT => in_array($newState, [self::POLLING, self::DELIVERED, self::ACCEPTED, self::REJECTED, self::FAILED, self::STORED], true),
            self::POLLING => in_array($newState, [self::DELIVERED, self::ACCEPTED, self::REJECTED, self::FAILED, self::STORED], true),
            self::DELIVERED => in_array($newState, [self::ACCEPTED, self::REJECTED], true),
            // Terminal states cannot transition
            self::ACCEPTED, self::REJECTED, self::FAILED, self::CANCELLED, self::STORED => false,
        };
    }

    // ============================================================================
    // Static Helper Methods for Queries
    // ============================================================================

    /**
     * Get state values that need polling.
     *
     * @return array<string>
     */
    public static function needsPollingValues(): array
    {
        return [
            self::SENT->value,
            self::POLLING->value,
        ];
    }

    /**
     * Get state values that are awaiting delivery.
     *
     * @return array<string>
     */
    public static function awaitingDeliveryValues(): array
    {
        return [
            self::SENT->value,
            self::POLLING->value,
        ];
    }

    /**
     * Get state values that are successfully completed.
     *
     * @return array<string>
     */
    public static function successValues(): array
    {
        return [
            self::DELIVERED->value,
            self::ACCEPTED->value,
            self::STORED->value,
        ];
    }

    /**
     * Get state values that represent failures.
     *
     * @return array<string>
     */
    public static function failureValues(): array
    {
        return [
            self::SEND_FAILED->value,
            self::REJECTED->value,
            self::FAILED->value,
        ];
    }

    /**
     * Get state values that are pending dispatch.
     *
     * @return array<string>
     */
    public static function pendingDispatchValues(): array
    {
        return [
            self::SCHEDULED->value,
            self::SEND_FAILED->value,
        ];
    }

    /**
     * Get state values that are currently in progress.
     *
     * @return array<string>
     */
    public static function inProgressValues(): array
    {
        return [
            self::SENDING->value,
            self::SENT->value,
            self::POLLING->value,
        ];
    }

    /**
     * Get state values that are final (no more processing).
     *
     * @return array<string>
     */
    public static function finalValues(): array
    {
        return [
            self::DELIVERED->value,
            self::ACCEPTED->value,
            self::REJECTED->value,
            self::FAILED->value,
            self::CANCELLED->value,
            self::STORED->value,
        ];
    }
}
