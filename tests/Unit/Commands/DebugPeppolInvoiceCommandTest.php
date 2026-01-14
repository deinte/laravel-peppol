<?php

declare(strict_types=1);

use Deinte\Peppol\Commands\DebugPeppolInvoiceCommand;
use Deinte\Peppol\Connectors\CircuitBreakerConnector;
use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Invoice;

describe('DebugPeppolInvoiceCommand', function () {
    describe('previewConnectorPayload()', function () {
        it('returns fallback for unknown connector types', function () {
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

            $mockConnector = Mockery::mock(PeppolConnector::class);

            $result = DebugPeppolInvoiceCommand::previewConnectorPayload($invoice, $mockConnector);

            expect($result['connector_payload'])->toBe('Preview not available for this connector');
        });

        it('unwraps CircuitBreakerConnector before checking connector type', function () {
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

            // Create a mock inner connector (not ScradaConnector)
            $mockInnerConnector = Mockery::mock(PeppolConnector::class);

            $circuitBreaker = new CircuitBreakerConnector(
                connector: $mockInnerConnector,
            );

            $result = DebugPeppolInvoiceCommand::previewConnectorPayload($invoice, $circuitBreaker);

            // Since the inner connector is not a ScradaConnector, it should return the fallback
            // This proves that the CircuitBreakerConnector was unwrapped to check the inner type
            expect($result['connector_payload'])->toBe('Preview not available for this connector');
            expect($result['connector'])->toContain('Mockery');
        });
    });
});
