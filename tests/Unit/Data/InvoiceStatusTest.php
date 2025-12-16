<?php

declare(strict_types=1);

use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\PeppolStatus;

describe('InvoiceStatus DTO', function () {
    it('creates an invoice status with all fields', function () {
        $updatedAt = new DateTimeImmutable('2024-01-15 10:30:00');

        $status = new InvoiceStatus(
            connectorInvoiceId: 'INV-123',
            status: PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
            updatedAt: $updatedAt,
            message: 'Invoice delivered successfully',
            metadata: ['delivery_id' => 'D-456'],
        );

        expect($status->connectorInvoiceId)->toBe('INV-123');
        expect($status->status)->toBe(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION);
        expect($status->updatedAt)->toBe($updatedAt);
        expect($status->message)->toBe('Invoice delivered successfully');
        expect($status->metadata)->toBe(['delivery_id' => 'D-456']);
    });

    it('creates an invoice status without optional fields', function () {
        $status = new InvoiceStatus(
            connectorInvoiceId: 'INV-123',
            status: PeppolStatus::PENDING,
            updatedAt: new DateTimeImmutable,
        );

        expect($status->message)->toBeNull();
        expect($status->metadata)->toBeNull();
    });

    describe('toArray()', function () {
        it('serializes all fields', function () {
            $updatedAt = new DateTimeImmutable('2024-01-15 10:30:00');

            $status = new InvoiceStatus(
                connectorInvoiceId: 'INV-123',
                status: PeppolStatus::ACCEPTED,
                updatedAt: $updatedAt,
                message: 'Accepted by recipient',
                metadata: ['acceptance_id' => 'A-789'],
            );

            $array = $status->toArray();

            expect($array)->toBe([
                'connector_invoice_id' => 'INV-123',
                'status' => 'ACCEPTED',
                'updated_at' => '2024-01-15 10:30:00',
                'message' => 'Accepted by recipient',
                'metadata' => ['acceptance_id' => 'A-789'],
            ]);
        });

        it('serializes with null optional fields', function () {
            $status = new InvoiceStatus(
                connectorInvoiceId: 'INV-123',
                status: PeppolStatus::PENDING,
                updatedAt: new DateTimeImmutable('2024-01-15 10:30:00'),
            );

            $array = $status->toArray();

            expect($array['message'])->toBeNull();
            expect($array['metadata'])->toBeNull();
        });
    });

    describe('fromArray()', function () {
        it('deserializes all fields', function () {
            $status = InvoiceStatus::fromArray([
                'connector_invoice_id' => 'INV-123',
                'status' => 'ACCEPTED',
                'updated_at' => '2024-01-15 10:30:00',
                'message' => 'Accepted by recipient',
                'metadata' => ['acceptance_id' => 'A-789'],
            ]);

            expect($status->connectorInvoiceId)->toBe('INV-123');
            expect($status->status)->toBe(PeppolStatus::ACCEPTED);
            expect($status->updatedAt->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:30:00');
            expect($status->message)->toBe('Accepted by recipient');
            expect($status->metadata)->toBe(['acceptance_id' => 'A-789']);
        });

        it('deserializes with missing optional fields', function () {
            $status = InvoiceStatus::fromArray([
                'connector_invoice_id' => 'INV-123',
                'status' => 'PENDING',
                'updated_at' => '2024-01-15 10:30:00',
            ]);

            expect($status->connectorInvoiceId)->toBe('INV-123');
            expect($status->status)->toBe(PeppolStatus::PENDING);
            expect($status->message)->toBeNull();
            expect($status->metadata)->toBeNull();
        });
    });

    describe('status values', function () {
        it('can represent all peppol statuses', function () {
            $statuses = [
                PeppolStatus::CREATED,
                PeppolStatus::PENDING,
                PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
                PeppolStatus::ACCEPTED,
                PeppolStatus::REJECTED,
                PeppolStatus::FAILED_DELIVERY,
            ];

            foreach ($statuses as $peppolStatus) {
                $status = new InvoiceStatus(
                    connectorInvoiceId: 'INV-123',
                    status: $peppolStatus,
                    updatedAt: new DateTimeImmutable,
                );

                expect($status->status)->toBe($peppolStatus);
            }
        });
    });
});
