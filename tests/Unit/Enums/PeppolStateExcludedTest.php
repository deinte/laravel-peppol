<?php

declare(strict_types=1);

use Deinte\Peppol\Enums\PeppolState;

describe('PeppolState::EXCLUDED', function () {
    it('is final but neither success nor failure', function () {
        expect(PeppolState::EXCLUDED->isFinal())->toBeTrue();
        expect(PeppolState::EXCLUDED->isSuccess())->toBeFalse();
        expect(PeppolState::EXCLUDED->isFailure())->toBeFalse();
    });

    it('is kept out of the failure, success and pending value sets', function () {
        expect(PeppolState::failureValues())->not->toContain('excluded');
        expect(PeppolState::successValues())->not->toContain('excluded');
        expect(PeppolState::pendingDispatchValues())->not->toContain('excluded');
        expect(PeppolState::finalValues())->toContain('excluded');
    });

    it('has a human readable label', function () {
        expect(PeppolState::EXCLUDED->label())->toBe('Excluded');
    });

    it('can be reached from failed, rejected, scheduled and send_failed', function () {
        expect(PeppolState::FAILED->canTransitionTo(PeppolState::EXCLUDED))->toBeTrue();
        expect(PeppolState::REJECTED->canTransitionTo(PeppolState::EXCLUDED))->toBeTrue();
        expect(PeppolState::SCHEDULED->canTransitionTo(PeppolState::EXCLUDED))->toBeTrue();
        expect(PeppolState::SEND_FAILED->canTransitionTo(PeppolState::EXCLUDED))->toBeTrue();
    });

    it('can only be restored back to scheduled', function () {
        expect(PeppolState::EXCLUDED->canTransitionTo(PeppolState::SCHEDULED))->toBeTrue();
        expect(PeppolState::EXCLUDED->canTransitionTo(PeppolState::SENDING))->toBeFalse();
    });

    it('cannot be reached from a delivered invoice', function () {
        expect(PeppolState::DELIVERED->canTransitionTo(PeppolState::EXCLUDED))->toBeFalse();
    });
});
