<?php

declare(strict_types=1);

use Deinte\Peppol\Connectors\ScradaConnector;
use Deinte\Peppol\Data\Invoice;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    // ScradaConnector logs to the 'peppol' channel via the Log facade.
    // Bind a no-op logger to satisfy that without booting full Laravel.
    $container = new Illuminate\Container\Container;
    Illuminate\Support\Facades\Facade::setFacadeApplication($container);

    $logManager = Mockery::mock();
    $logManager->shouldReceive('channel')->andReturnSelf();
    $logManager->shouldReceive('debug')->andReturnNull();
    $logManager->shouldReceive('info')->andReturnNull();
    $logManager->shouldReceive('warning')->andReturnNull();
    $logManager->shouldReceive('error')->andReturnNull();

    $container->instance('log', $logManager);
});

afterEach(function () {
    Mockery::close();
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
});

function buildPeppolInvoice(array $overrides = []): Invoice
{
    $defaults = [
        'totalAmount' => 3630.08,
        'lineItems' => [
            [
                'description' => 'Line 1',
                'quantity' => 1,
                'unitPrice' => 1500.00,
                'vatPerc' => 21,
                'totalExclVat' => 1500.00,
                'vatAmount' => 315.035,
                'totalInclVat' => 1815.035,
                'vatCountry' => 'BE',
            ],
            [
                'description' => 'Line 2',
                'quantity' => 1,
                'unitPrice' => 1500.00,
                'vatPerc' => 21,
                'totalExclVat' => 1500.00,
                'vatAmount' => 315.035,
                'totalInclVat' => 1815.035,
                'vatCountry' => 'BE',
            ],
        ],
        'payableRoundingAmount' => null,
    ];

    $merged = array_merge($defaults, $overrides);

    return new Invoice(
        senderVatNumber: 'BE0123456789',
        recipientVatNumber: 'BE0987654321',
        recipientPeppolId: null,
        invoiceNumber: 'INV-001',
        invoiceDate: new DateTimeImmutable('2026-04-01'),
        dueDate: new DateTimeImmutable('2026-05-01'),
        totalAmount: $merged['totalAmount'],
        currency: 'EUR',
        lineItems: $merged['lineItems'],
        payableRoundingAmount: $merged['payableRoundingAmount'],
        additionalData: [
            'customer' => [
                'name' => 'Test BV',
                'address' => [
                    'street' => 'Teststraat',
                    'streetNumber' => '1',
                    'city' => 'Brussels',
                    'zipCode' => '1000',
                    'countryCode' => 'BE',
                ],
                'email' => 'test@example.com',
            ],
        ],
    );
}

function buildScradaConnector(): ScradaConnector
{
    return new ScradaConnector(
        apiKey: 'test',
        apiSecret: 'test',
        companyId: 'test',
    );
}

describe('ScradaConnector::transformInvoiceToScradaFormat()', function () {
    it('preserves header totalVat from line item sum when payableRoundingAmount is provided', function () {
        // Sum of line vatAmount = 315.035 + 315.035 = 630.07 (after round)
        // Invoice header total reflects 630.08 of VAT, so the 1-cent gap is
        // declared via payableRoundingAmount instead of overriding totalVat.
        $invoice = buildPeppolInvoice([
            'totalAmount' => 3630.08,
            'payableRoundingAmount' => 0.01,
        ]);

        $result = buildScradaConnector()->transformInvoiceToScradaFormat($invoice);

        expect($result->totalVat)->toBe(630.07);
        expect($result->totalExclVat)->toBe(3000.0);
        expect($result->totalInclVat)->toBe(3630.07);
        expect($result->payableRoundingAmount)->toBe(0.01);
    });

    it('keeps line vatAmount sum equal to header totalVat', function () {
        // This is the core invariant Scrada checks (error 110424).
        $invoice = buildPeppolInvoice([
            'totalAmount' => 3630.08,
            'payableRoundingAmount' => 0.01,
        ]);

        $result = buildScradaConnector()->transformInvoiceToScradaFormat($invoice);

        $lineVatSum = round(array_sum(array_map(
            fn ($line) => $line->vatAmount,
            $result->lines
        )), 2);

        expect($lineVatSum)->toBe($result->totalVat);
    });

    it('always uses line-sum totals regardless of invoice totalAmount mismatch', function () {
        // The connector trusts the line items as the source of truth.
        // Header totals are derived from line sums; any gap to invoice->totalAmount
        // must be declared via payableRoundingAmount by the caller.
        $invoice = buildPeppolInvoice([
            'totalAmount' => 3630.10, // 3 cents above line items total
            'payableRoundingAmount' => null,
        ]);

        $result = buildScradaConnector()->transformInvoiceToScradaFormat($invoice);

        expect($result->totalVat)->toBe(630.07);
        expect($result->totalExclVat)->toBe(3000.0);
        expect($result->totalInclVat)->toBe(3630.07);
    });
});
