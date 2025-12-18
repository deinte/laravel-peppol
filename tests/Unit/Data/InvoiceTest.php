<?php

declare(strict_types=1);

use Deinte\Peppol\Data\Invoice;

describe('Invoice DTO', function () {
    it('creates an invoice with all required fields', function () {
        $invoice = new Invoice(
            senderVatNumber: 'BE0123456789',
            recipientVatNumber: 'NL123456789B01',
            recipientPeppolId: '0106:12345678',
            invoiceNumber: 'INV-2024-001',
            invoiceDate: new DateTimeImmutable('2024-01-15'),
            dueDate: new DateTimeImmutable('2024-02-15'),
            totalAmount: 1210.00,
            currency: 'EUR',
            lineItems: [
                ['description' => 'Service A', 'quantity' => 1, 'unitPrice' => 1000.00, 'vatPerc' => 21],
            ],
        );

        expect($invoice->senderVatNumber)->toBe('BE0123456789');
        expect($invoice->recipientVatNumber)->toBe('NL123456789B01');
        expect($invoice->recipientPeppolId)->toBe('0106:12345678');
        expect($invoice->invoiceNumber)->toBe('INV-2024-001');
        expect($invoice->totalAmount)->toBe(1210.00);
        expect($invoice->currency)->toBe('EUR');
        expect($invoice->lineItems)->toHaveCount(1);
    });

    it('creates an invoice with optional fields', function () {
        $invoice = new Invoice(
            senderVatNumber: 'BE0123456789',
            recipientVatNumber: 'NL123456789B01',
            recipientPeppolId: '0106:12345678',
            invoiceNumber: 'INV-2024-001',
            invoiceDate: new DateTimeImmutable('2024-01-15'),
            dueDate: new DateTimeImmutable('2024-02-15'),
            totalAmount: 1210.00,
            currency: 'EUR',
            lineItems: [],
            pdfPath: '/path/to/invoice.pdf',
            pdfContent: base64_encode('PDF content'),
            pdfFilename: 'invoice.pdf',
            alreadySentToCustomer: true,
            additionalData: ['journal' => 'SALES'],
        );

        expect($invoice->pdfPath)->toBe('/path/to/invoice.pdf');
        expect($invoice->pdfContent)->toBe(base64_encode('PDF content'));
        expect($invoice->pdfFilename)->toBe('invoice.pdf');
        expect($invoice->alreadySentToCustomer)->toBeTrue();
        expect($invoice->additionalData)->toBe(['journal' => 'SALES']);
    });

    describe('hasPdf()', function () {
        it('returns true when pdfPath is set', function () {
            $invoice = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-2024-001',
                invoiceDate: new DateTimeImmutable('2024-01-15'),
                dueDate: new DateTimeImmutable('2024-02-15'),
                totalAmount: 1210.00,
                currency: 'EUR',
                lineItems: [],
                pdfPath: '/path/to/invoice.pdf',
            );

            expect($invoice->hasPdf())->toBeTrue();
        });

        it('returns true when pdfContent is set', function () {
            $invoice = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-2024-001',
                invoiceDate: new DateTimeImmutable('2024-01-15'),
                dueDate: new DateTimeImmutable('2024-02-15'),
                totalAmount: 1210.00,
                currency: 'EUR',
                lineItems: [],
                pdfContent: base64_encode('PDF content'),
            );

            expect($invoice->hasPdf())->toBeTrue();
        });

        it('returns false when neither pdfPath nor pdfContent is set', function () {
            $invoice = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-2024-001',
                invoiceDate: new DateTimeImmutable('2024-01-15'),
                dueDate: new DateTimeImmutable('2024-02-15'),
                totalAmount: 1210.00,
                currency: 'EUR',
                lineItems: [],
            );

            expect($invoice->hasPdf())->toBeFalse();
        });
    });

    describe('toArray()', function () {
        it('serializes all fields', function () {
            $invoiceDate = new DateTimeImmutable('2024-01-15');
            $dueDate = new DateTimeImmutable('2024-02-15');
            $pdfContent = base64_encode('PDF content');

            $invoice = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-2024-001',
                invoiceDate: $invoiceDate,
                dueDate: $dueDate,
                totalAmount: 1210.00,
                currency: 'EUR',
                lineItems: [
                    ['description' => 'Service A', 'quantity' => 1, 'unitPrice' => 1000.00],
                ],
                pdfPath: '/path/to/invoice.pdf',
                pdfContent: $pdfContent,
                pdfFilename: 'invoice.pdf',
                alreadySentToCustomer: true,
                additionalData: ['journal' => 'SALES'],
            );

            $array = $invoice->toArray();

            expect($array)->toBe([
                'sender_vat_number' => 'BE0123456789',
                'recipient_vat_number' => 'NL123456789B01',
                'recipient_peppol_id' => '0106:12345678',
                'invoice_number' => 'INV-2024-001',
                'invoice_date' => '2024-01-15',
                'due_date' => '2024-02-15',
                'total_amount' => 1210.00,
                'currency' => 'EUR',
                'line_items' => [
                    ['description' => 'Service A', 'quantity' => 1, 'unitPrice' => 1000.00],
                ],
                'pdf_path' => '/path/to/invoice.pdf',
                'pdf_content' => '[BASE64_CONTENT]',
                'pdf_filename' => 'invoice.pdf',
                'already_sent_to_customer' => true,
                'payment_methods' => [],
                'additional_data' => ['journal' => 'SALES'],
            ]);
        });

        it('serializes with null optional fields', function () {
            $invoice = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-2024-001',
                invoiceDate: new DateTimeImmutable('2024-01-15'),
                dueDate: new DateTimeImmutable('2024-02-15'),
                totalAmount: 1210.00,
                currency: 'EUR',
                lineItems: [],
            );

            $array = $invoice->toArray();

            expect($array['pdf_path'])->toBeNull();
            expect($array['pdf_content'])->toBeNull();
            expect($array['pdf_filename'])->toBeNull();
            expect($array['already_sent_to_customer'])->toBeFalse();
            expect($array['additional_data'])->toBeNull();
        });
    });
});
