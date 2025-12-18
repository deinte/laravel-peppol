<?php

declare(strict_types=1);

use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\EasCode;
use Deinte\Peppol\Enums\PeppolState;
use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Models\PeppolCompany;
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\PeppolService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockConnector = Mockery::mock(PeppolConnector::class);
    $this->service = new PeppolService($this->mockConnector);
});

describe('PeppolService', function () {
    describe('lookupCompany()', function () {
        it('returns cached company if cache is valid', function () {
            // Create a cached company
            $cachedCompany = PeppolCompany::create([
                'vat_number' => 'BE0123456789',
                'peppol_id' => '0208:0123456789',
                'name' => 'Test Company',
                'country' => 'BE',
                'last_lookup_at' => now()->subHours(1), // Recent lookup
            ]);

            // Connector should not be called
            $this->mockConnector->shouldNotReceive('lookupCompany');

            $result = $this->service->lookupCompany('BE0123456789');

            expect($result)->not->toBeNull();
            expect($result->id)->toBe($cachedCompany->id);
            expect($result->vat_number)->toBe('BE0123456789');
        });

        it('refreshes cache when expired', function () {
            // Create an expired cached company
            PeppolCompany::create([
                'vat_number' => 'BE0123456789',
                'peppol_id' => '0208:0123456789',
                'name' => 'Old Company',
                'country' => 'BE',
                'last_lookup_at' => now()->subWeeks(2), // Expired
            ]);

            $this->mockConnector->shouldReceive('lookupCompany')
                ->once()
                ->with('BE0123456789', null, null)
                ->andReturn(new Company(
                    vatNumber: 'BE0123456789',
                    peppolId: '0208:0123456789',
                    name: 'Updated Company',
                    country: 'BE',
                ));

            $result = $this->service->lookupCompany('BE0123456789');

            expect($result)->not->toBeNull();
            expect($result->name)->toBe('Updated Company');
        });

        it('forces refresh when requested', function () {
            // Create a fresh cached company
            PeppolCompany::create([
                'vat_number' => 'BE0123456789',
                'peppol_id' => '0208:0123456789',
                'name' => 'Cached Company',
                'country' => 'BE',
                'last_lookup_at' => now()->subMinutes(5),
            ]);

            $this->mockConnector->shouldReceive('lookupCompany')
                ->once()
                ->with('BE0123456789', null, null)
                ->andReturn(new Company(
                    vatNumber: 'BE0123456789',
                    peppolId: '0208:0123456789',
                    name: 'Fresh Company',
                    country: 'BE',
                ));

            $result = $this->service->lookupCompany('BE0123456789', forceRefresh: true);

            expect($result->name)->toBe('Fresh Company');
        });

        it('caches new company lookup', function () {
            $this->mockConnector->shouldReceive('lookupCompany')
                ->once()
                ->with('NL123456789B01', null, null)
                ->andReturn(new Company(
                    vatNumber: 'NL123456789B01',
                    peppolId: '0106:12345678',
                    name: 'Dutch Company',
                    country: 'NL',
                ));

            $result = $this->service->lookupCompany('NL123456789B01');

            expect($result)->not->toBeNull();
            expect($result->vat_number)->toBe('NL123456789B01');

            // Verify it was cached
            $cached = PeppolCompany::findByVatNumber('NL123456789B01');
            expect($cached)->not->toBeNull();
            expect($cached->peppol_id)->toBe('0106:12345678');
        });

        it('passes tax number and country to connector', function () {
            $this->mockConnector->shouldReceive('lookupCompany')
                ->once()
                ->with('BE0123456789', '0123456789', 'BE')
                ->andReturn(new Company(
                    vatNumber: 'BE0123456789',
                    peppolId: '0208:0123456789',
                    country: 'BE',
                    taxNumber: '0123456789',
                    taxNumberScheme: EasCode::BE_CBE,
                ));

            $result = $this->service->lookupCompany(
                vatNumber: 'BE0123456789',
                taxNumber: '0123456789',
                country: 'BE',
            );

            // PeppolCompany no longer stores tax_number - just verify the lookup succeeded
            expect($result)->not->toBeNull();
            expect($result->peppol_id)->toBe('0208:0123456789');
        });

        it('returns null when connector returns null', function () {
            $this->mockConnector->shouldReceive('lookupCompany')
                ->once()
                ->andReturn(null);

            $result = $this->service->lookupCompany('XX999999999');

            expect($result)->toBeNull();
        });
    });

    describe('scheduleInvoice()', function () {
        it('schedules an invoice for a company on PEPPOL', function () {
            // Create recipient company
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'name' => 'Dutch Company',
                'country' => 'NL',
                'last_lookup_at' => now(),
            ]);

            // Mock connector - should not be called since company is cached
            $this->mockConnector->shouldNotReceive('lookupCompany');

            // Create a fake invoiceable model
            $invoice = new class extends Illuminate\Database\Eloquent\Model
            {
                protected $table = 'test_invoices';

                public $incrementing = true;

                public function getKey()
                {
                    return 1;
                }
            };

            $peppolInvoice = $this->service->scheduleInvoice(
                invoice: $invoice,
                recipientVatNumber: 'NL123456789B01',
            );

            expect($peppolInvoice)->toBeInstanceOf(PeppolInvoice::class);
            expect($peppolInvoice->recipient_peppol_company_id)->toBe($recipient->id);
            expect($peppolInvoice->state)->toBe(PeppolState::SCHEDULED);
            expect($peppolInvoice->scheduled_at)->not->toBeNull();
        });

        it('schedules invoice with skip_delivery only when explicitly set', function () {
            // Create recipient company without peppol_id
            PeppolCompany::create([
                'vat_number' => 'XX999999999',
                'peppol_id' => null,
                'name' => 'Non-PEPPOL Company',
                'last_lookup_at' => now(),
            ]);

            $invoice = new class extends Illuminate\Database\Eloquent\Model
            {
                protected $table = 'test_invoices';

                public $incrementing = true;

                public function getKey()
                {
                    return 1;
                }
            };

            // Without explicit skip_delivery, defaults to false (let Scrada handle routing)
            $peppolInvoice = $this->service->scheduleInvoice($invoice, 'XX999999999');

            expect($peppolInvoice)->toBeInstanceOf(PeppolInvoice::class);
            expect($peppolInvoice->skip_delivery)->toBeFalse();
            expect($peppolInvoice->state)->toBe(PeppolState::SCHEDULED);

            // With explicit skip_delivery=true
            $peppolInvoice2 = $this->service->scheduleInvoice($invoice, 'XX999999999', skipDelivery: true);
            expect($peppolInvoice2->skip_delivery)->toBeTrue();
        });
    });

    describe('dispatchInvoice()', function () {
        it('dispatches invoice via connector and updates record', function () {
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'state' => PeppolState::SCHEDULED,
            ]);

            $invoiceData = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-001',
                invoiceDate: new DateTimeImmutable,
                dueDate: new DateTimeImmutable('+30 days'),
                totalAmount: 1000.00,
                currency: 'EUR',
                lineItems: [],
            );

            $this->mockConnector->shouldReceive('sendInvoice')
                ->once()
                ->with($invoiceData)
                ->andReturn(new InvoiceStatus(
                    connectorInvoiceId: 'SCRADA-123',
                    status: PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
                    updatedAt: new DateTimeImmutable,
                    message: 'Sent successfully',
                ));

            $result = $this->service->dispatchInvoice($peppolInvoice, $invoiceData);

            expect($result->connectorInvoiceId)->toBe('SCRADA-123');
            expect($result->status)->toBe(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION);

            $peppolInvoice->refresh();
            expect($peppolInvoice->connector_invoice_id)->toBe('SCRADA-123');
            expect($peppolInvoice->sent_at)->not->toBeNull();
            expect($peppolInvoice->state)->toBe(PeppolState::SENT);
        });

        it('transitions to SENT state for non-PEPPOL recipients and lets polling determine final state', function () {
            // Create recipient company WITHOUT peppol_id (not on PEPPOL)
            // Note: We don't pre-decide based on cached PEPPOL status - Scrada handles routing
            $recipient = PeppolCompany::create([
                'vat_number' => 'XX999999999',
                'peppol_id' => null,
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'state' => PeppolState::SCHEDULED,
            ]);

            $invoiceData = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'XX999999999',
                recipientPeppolId: null,
                invoiceNumber: 'INV-002',
                invoiceDate: new DateTimeImmutable,
                dueDate: new DateTimeImmutable('+30 days'),
                totalAmount: 1000.00,
                currency: 'EUR',
                lineItems: [],
            );

            $this->mockConnector->shouldReceive('sendInvoice')
                ->once()
                ->with($invoiceData)
                ->andReturn(new InvoiceStatus(
                    connectorInvoiceId: 'SCRADA-456',
                    status: PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
                    updatedAt: new DateTimeImmutable,
                    message: 'Stored in connector',
                ));

            $result = $this->service->dispatchInvoice($peppolInvoice, $invoiceData);

            $peppolInvoice->refresh();
            // Goes to SENT first - polling will transition to STORED when Scrada reports recipientNotOnPeppol
            expect($peppolInvoice->state)->toBe(PeppolState::SENT);
            expect($peppolInvoice->connector_invoice_id)->toBe('SCRADA-456');
            expect($peppolInvoice->sent_at)->not->toBeNull();
            expect($peppolInvoice->completed_at)->toBeNull(); // Not completed until polling determines final state

            // Will be polled to determine final state
            expect($peppolInvoice->needsPolling())->toBeTrue();
        });

        it('transitions to STORED state when skip_delivery is true', function () {
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'state' => PeppolState::SCHEDULED,
                'skip_delivery' => true,
            ]);

            $invoiceData = new Invoice(
                senderVatNumber: 'BE0123456789',
                recipientVatNumber: 'NL123456789B01',
                recipientPeppolId: '0106:12345678',
                invoiceNumber: 'INV-003',
                invoiceDate: new DateTimeImmutable,
                dueDate: new DateTimeImmutable('+30 days'),
                totalAmount: 1000.00,
                currency: 'EUR',
                lineItems: [],
            );

            $this->mockConnector->shouldReceive('sendInvoice')
                ->once()
                ->andReturn(new InvoiceStatus(
                    connectorInvoiceId: 'SCRADA-789',
                    status: PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
                    updatedAt: new DateTimeImmutable,
                ));

            $result = $this->service->dispatchInvoice($peppolInvoice, $invoiceData);

            $peppolInvoice->refresh();
            expect($peppolInvoice->state)->toBe(PeppolState::STORED);
            expect($peppolInvoice->completed_at)->not->toBeNull();
            expect($peppolInvoice->needsPolling())->toBeFalse();
        });
    });

    describe('getInvoiceStatus()', function () {
        it('gets status from connector', function () {
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'connector_invoice_id' => 'SCRADA-123',
                'state' => PeppolState::SENT,
                'sent_at' => now(),
            ]);

            $this->mockConnector->shouldReceive('getInvoiceStatus')
                ->once()
                ->with('SCRADA-123')
                ->andReturn(new InvoiceStatus(
                    connectorInvoiceId: 'SCRADA-123',
                    status: PeppolStatus::ACCEPTED,
                    updatedAt: new DateTimeImmutable,
                ));

            $result = $this->service->getInvoiceStatus($peppolInvoice);

            expect($result->status)->toBe(PeppolStatus::ACCEPTED);

            $peppolInvoice->refresh();
            expect($peppolInvoice->state)->toBe(PeppolState::ACCEPTED);
        });

        it('throws exception if invoice not dispatched', function () {
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'state' => PeppolState::SCHEDULED,
            ]);

            expect(fn () => $this->service->getInvoiceStatus($peppolInvoice))
                ->toThrow(RuntimeException::class, 'not been dispatched');
        });
    });

    describe('getUblFile()', function () {
        it('gets UBL from connector', function () {
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'connector_invoice_id' => 'SCRADA-123',
                'state' => PeppolState::DELIVERED,
                'sent_at' => now(),
            ]);

            $expectedUbl = '<?xml version="1.0"?><Invoice>...</Invoice>';

            $this->mockConnector->shouldReceive('getUblFile')
                ->once()
                ->with('SCRADA-123')
                ->andReturn($expectedUbl);

            $result = $this->service->getUblFile($peppolInvoice);

            expect($result)->toBe($expectedUbl);
        });

        it('throws exception if invoice not dispatched', function () {
            $recipient = PeppolCompany::create([
                'vat_number' => 'NL123456789B01',
                'peppol_id' => '0106:12345678',
                'last_lookup_at' => now(),
            ]);

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => 'App\\Models\\Invoice',
                'invoiceable_id' => 1,
                'recipient_peppol_company_id' => $recipient->id,
                'state' => PeppolState::SCHEDULED,
            ]);

            expect(fn () => $this->service->getUblFile($peppolInvoice))
                ->toThrow(RuntimeException::class, 'not been dispatched');
        });
    });
});
