<?php

declare(strict_types=1);

namespace Deinte\Peppol\Enums;

/**
 * Standardized PEPPOL invoice status codes.
 *
 * Based on the Scrada documentation, these represent the lifecycle
 * of an invoice on the PEPPOL network.
 */
enum PeppolStatus: string
{
    /**
     * Invoice has been created and queued for sending.
     */
    case CREATED = 'CREATED';

    /**
     * Invoice has been delivered without confirmation from recipient.
     */
    case DELIVERED_WITHOUT_CONFIRMATION = 'DELIVERED_WITHOUT_CONFIRMATION';

    /**
     * Invoice has been accepted by the recipient.
     */
    case ACCEPTED = 'ACCEPTED';

    /**
     * Invoice was rejected by the recipient.
     */
    case REJECTED = 'REJECTED';

    /**
     * Delivery of the invoice failed.
     */
    case FAILED_DELIVERY = 'FAILED_DELIVERY';

    /**
     * Invoice is pending processing.
     */
    case PENDING = 'PENDING';

    /**
     * Check if the status indicates successful delivery.
     */
    public function isDelivered(): bool
    {
        return in_array($this, [
            self::DELIVERED_WITHOUT_CONFIRMATION,
            self::ACCEPTED,
        ]);
    }

    /**
     * Check if the status indicates a failure.
     */
    public function isFailed(): bool
    {
        return in_array($this, [
            self::REJECTED,
            self::FAILED_DELIVERY,
        ]);
    }

    /**
     * Check if the status indicates the invoice is still in progress.
     */
    public function isPending(): bool
    {
        return in_array($this, [
            self::CREATED,
            self::PENDING,
        ]);
    }

    /**
     * Get a human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Created',
            self::DELIVERED_WITHOUT_CONFIRMATION => 'Delivered',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
            self::FAILED_DELIVERY => 'Failed',
            self::PENDING => 'Pending',
        };
    }

    /**
     * Get an icon representation for UI display.
     */
    public function icon(): string
    {
        return match ($this) {
            self::CREATED, self::PENDING => '⏳',
            self::DELIVERED_WITHOUT_CONFIRMATION, self::ACCEPTED => '✅',
            self::REJECTED, self::FAILED_DELIVERY => '⚠️',
        };
    }
}
