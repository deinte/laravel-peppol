<?php

declare(strict_types=1);

namespace Deinte\Peppol\Data;

/**
 * Represents an invoice to be sent via PEPPOL.
 */
class Invoice
{
    public function __construct(
        public readonly string $senderVatNumber,
        public readonly string $recipientVatNumber,
        public readonly string $recipientPeppolId,
        public readonly string $invoiceNumber,
        public readonly \DateTimeInterface $invoiceDate,
        public readonly \DateTimeInterface $dueDate,
        public readonly float $totalAmount,
        public readonly string $currency,
        public readonly array $lineItems,
        public readonly ?string $pdfPath = null,
        public readonly ?string $pdfUrl = null,
        public readonly bool $alreadySentToCustomer = false,
        public readonly ?array $additionalData = null,
    ) {}

    public function hasPdf(): bool
    {
        return $this->pdfPath !== null || $this->pdfUrl !== null;
    }

    public function toArray(): array
    {
        return [
            'sender_vat_number' => $this->senderVatNumber,
            'recipient_vat_number' => $this->recipientVatNumber,
            'recipient_peppol_id' => $this->recipientPeppolId,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => $this->invoiceDate->format('Y-m-d'),
            'due_date' => $this->dueDate->format('Y-m-d'),
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'line_items' => $this->lineItems,
            'pdf_path' => $this->pdfPath,
            'pdf_url' => $this->pdfUrl,
            'already_sent_to_customer' => $this->alreadySentToCustomer,
            'additional_data' => $this->additionalData,
        ];
    }
}
