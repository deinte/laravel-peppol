<?php

declare(strict_types=1);

use Deinte\Peppol\Enums\PeppolStatus;

describe('PeppolStatus enum', function () {
    describe('status values', function () {
        it('has all expected status values', function () {
            expect(PeppolStatus::cases())->toHaveCount(6);
            expect(PeppolStatus::CREATED->value)->toBe('CREATED');
            expect(PeppolStatus::PENDING->value)->toBe('PENDING');
            expect(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->value)->toBe('DELIVERED_WITHOUT_CONFIRMATION');
            expect(PeppolStatus::ACCEPTED->value)->toBe('ACCEPTED');
            expect(PeppolStatus::REJECTED->value)->toBe('REJECTED');
            expect(PeppolStatus::FAILED_DELIVERY->value)->toBe('FAILED_DELIVERY');
        });
    });

    describe('isDelivered()', function () {
        it('returns true for DELIVERED_WITHOUT_CONFIRMATION', function () {
            expect(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->isDelivered())->toBeTrue();
        });

        it('returns true for ACCEPTED', function () {
            expect(PeppolStatus::ACCEPTED->isDelivered())->toBeTrue();
        });

        it('returns false for non-delivered statuses', function () {
            expect(PeppolStatus::CREATED->isDelivered())->toBeFalse();
            expect(PeppolStatus::PENDING->isDelivered())->toBeFalse();
            expect(PeppolStatus::REJECTED->isDelivered())->toBeFalse();
            expect(PeppolStatus::FAILED_DELIVERY->isDelivered())->toBeFalse();
        });
    });

    describe('isFailed()', function () {
        it('returns true for REJECTED', function () {
            expect(PeppolStatus::REJECTED->isFailed())->toBeTrue();
        });

        it('returns true for FAILED_DELIVERY', function () {
            expect(PeppolStatus::FAILED_DELIVERY->isFailed())->toBeTrue();
        });

        it('returns false for non-failed statuses', function () {
            expect(PeppolStatus::CREATED->isFailed())->toBeFalse();
            expect(PeppolStatus::PENDING->isFailed())->toBeFalse();
            expect(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->isFailed())->toBeFalse();
            expect(PeppolStatus::ACCEPTED->isFailed())->toBeFalse();
        });
    });

    describe('isPending()', function () {
        it('returns true for CREATED', function () {
            expect(PeppolStatus::CREATED->isPending())->toBeTrue();
        });

        it('returns true for PENDING', function () {
            expect(PeppolStatus::PENDING->isPending())->toBeTrue();
        });

        it('returns false for non-pending statuses', function () {
            expect(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->isPending())->toBeFalse();
            expect(PeppolStatus::ACCEPTED->isPending())->toBeFalse();
            expect(PeppolStatus::REJECTED->isPending())->toBeFalse();
            expect(PeppolStatus::FAILED_DELIVERY->isPending())->toBeFalse();
        });
    });

    describe('label()', function () {
        it('returns human-readable labels', function () {
            expect(PeppolStatus::CREATED->label())->toBe('Created');
            expect(PeppolStatus::PENDING->label())->toBe('Pending');
            expect(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->label())->toBe('Delivered');
            expect(PeppolStatus::ACCEPTED->label())->toBe('Accepted');
            expect(PeppolStatus::REJECTED->label())->toBe('Rejected');
            expect(PeppolStatus::FAILED_DELIVERY->label())->toBe('Failed');
        });
    });

    describe('icon()', function () {
        it('returns pending icon for pending statuses', function () {
            expect(PeppolStatus::CREATED->icon())->toBe('⏳');
            expect(PeppolStatus::PENDING->icon())->toBe('⏳');
        });

        it('returns success icon for delivered statuses', function () {
            expect(PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->icon())->toBe('✅');
            expect(PeppolStatus::ACCEPTED->icon())->toBe('✅');
        });

        it('returns warning icon for failed statuses', function () {
            expect(PeppolStatus::REJECTED->icon())->toBe('⚠️');
            expect(PeppolStatus::FAILED_DELIVERY->icon())->toBe('⚠️');
        });
    });

    describe('status lifecycle', function () {
        it('has mutually exclusive status categories', function () {
            foreach (PeppolStatus::cases() as $status) {
                $categories = array_filter([
                    $status->isDelivered(),
                    $status->isFailed(),
                    $status->isPending(),
                ]);

                // Each status should belong to exactly one category
                expect($categories)->toHaveCount(1);
            }
        });
    });
});
