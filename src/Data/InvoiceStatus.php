<?php

declare(strict_types=1);

namespace Deinte\Peppol\Data;

use DateTimeImmutable;
use DateTimeInterface;
use Deinte\Peppol\Enums\PeppolStatus;

/**
 * Represents the status of a PEPPOL invoice.
 */
class InvoiceStatus
{
    public function __construct(
        public readonly string $connectorInvoiceId,
        public readonly PeppolStatus $status,
        public readonly DateTimeInterface $updatedAt,
        public readonly ?string $message = null,
        public readonly ?array $metadata = null,
        public readonly bool $recipientNotOnPeppol = false,
        public readonly bool $connectorInternalError = false,
    ) {}

    public function toArray(): array
    {
        return [
            'connector_invoice_id' => $this->connectorInvoiceId,
            'status' => $this->status->value,
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            connectorInvoiceId: $data['connector_invoice_id'],
            status: PeppolStatus::from($data['status']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
            message: $data['message'] ?? null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
