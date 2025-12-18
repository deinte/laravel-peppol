<?php

declare(strict_types=1);

namespace Deinte\Peppol\Data;

/**
 * Represents an invoice to be sent via PEPPOL.
 *
 * PDF attachment can be provided in two ways:
 * - `pdfPath`: Local file path that the connector will read
 * - `pdfContent`: Base64-encoded PDF content (takes precedence over pdfPath)
 *
 * The transformer is responsible for reading PDFs from storage and providing
 * either the local path or the base64 content.
 */
class Invoice
{
    /**
     * @param  array<int, array{paymentType: int, name: string, totalPaid?: float, totalToPay?: float}>  $paymentMethods
     */
    public function __construct(
        public readonly string $senderVatNumber,
        public readonly string $recipientVatNumber,
        public readonly ?string $recipientPeppolId,
        public readonly string $invoiceNumber,
        public readonly \DateTimeInterface $invoiceDate,
        public readonly \DateTimeInterface $dueDate,
        public readonly float $totalAmount,
        public readonly string $currency,
        public readonly array $lineItems,
        public readonly ?string $pdfPath = null,
        public readonly ?string $pdfContent = null,
        public readonly ?string $pdfFilename = null,
        public readonly bool $alreadySentToCustomer = false,
        public readonly array $paymentMethods = [],
        public readonly ?array $additionalData = null,
    ) {}

    public function hasPdf(): bool
    {
        return $this->pdfContent !== null || $this->pdfPath !== null;
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
            'pdf_content' => $this->pdfContent ? '[BASE64_CONTENT]' : null,
            'pdf_filename' => $this->pdfFilename,
            'already_sent_to_customer' => $this->alreadySentToCustomer,
            'payment_methods' => $this->paymentMethods,
            'additional_data' => $this->additionalData,
        ];
    }
}
