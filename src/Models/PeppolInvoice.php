<?php

declare(strict_types=1);

namespace Deinte\Peppol\Models;

use Deinte\Peppol\Enums\PeppolState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * PEPPOL invoice tracking.
 *
 * Uses a single state machine for clear lifecycle tracking.
 * Polymorphic relationship allows any invoice model to use PEPPOL.
 *
 * @property int $id
 * @property string $invoiceable_type
 * @property int $invoiceable_id
 * @property int|null $recipient_peppol_company_id
 * @property PeppolState $state
 * @property string|null $error_message
 * @property array|null $error_details
 * @property string|null $connector_invoice_id
 * @property string $connector_type
 * @property int $dispatch_attempts
 * @property int $poll_attempts
 * @property \Illuminate\Support\Carbon|null $next_retry_at
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property bool $skip_delivery
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PeppolInvoice extends Model
{
    protected $fillable = [
        'invoiceable_type',
        'invoiceable_id',
        'recipient_peppol_company_id',
        'state',
        'error_message',
        'error_details',
        'connector_invoice_id',
        'connector_type',
        'dispatch_attempts',
        'poll_attempts',
        'next_retry_at',
        'scheduled_at',
        'sent_at',
        'completed_at',
        'skip_delivery',
    ];

    protected $casts = [
        'state' => PeppolState::class,
        'error_details' => 'array',
        'dispatch_attempts' => 'integer',
        'poll_attempts' => 'integer',
        'skip_delivery' => 'boolean',
        'next_retry_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'state' => 'scheduled',
        'connector_type' => 'scrada',
        'dispatch_attempts' => 0,
        'poll_attempts' => 0,
        'skip_delivery' => false,
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * Get the parent invoiceable model (your app's invoice).
     */
    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the recipient company (lookup cache).
     */
    public function recipientCompany(): BelongsTo
    {
        return $this->belongsTo(PeppolCompany::class, 'recipient_peppol_company_id');
    }

    /**
     * Get the activity logs for this invoice.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PeppolInvoiceLog::class)->orderBy('created_at', 'desc');
    }

    // =========================================================================
    // State Transitions
    // =========================================================================

    /**
     * Transition to a new state with logging.
     */
    public function transitionTo(
        PeppolState $newState,
        ?string $message = null,
        ?array $details = null,
        ?string $actor = null
    ): void {
        $fromState = $this->state;

        DB::transaction(function () use ($newState, $message, $details, $actor, $fromState) {
            $updateData = ['state' => $newState];

            // Set completed_at for final states
            if ($newState->isFinal() && $this->completed_at === null) {
                $updateData['completed_at'] = now();
            }

            // Clear error on success states
            if ($newState->isSuccess()) {
                $updateData['error_message'] = null;
                $updateData['error_details'] = null;
            }

            $this->update($updateData);

            // Log the transition
            $this->logs()->create([
                'from_state' => $fromState?->value,
                'to_state' => $newState->value,
                'message' => $message,
                'details' => $details,
                'actor' => $actor ?? 'system',
            ]);
        });
    }

    /**
     * Mark invoice as sending (API call starting).
     */
    public function markAsSending(): void
    {
        $this->update([
            'state' => PeppolState::SENDING,
            'dispatch_attempts' => $this->dispatch_attempts + 1,
        ]);
    }

    /**
     * Mark invoice as sent successfully.
     *
     * Transitions to STORED if:
     * - skip_delivery is explicitly set
     * - connector_invoice_id starts with "existing:" (can't be polled)
     *
     * Otherwise, transitions to SENT and lets polling determine final state.
     */
    public function markAsSent(string $connectorInvoiceId, ?string $message = null): void
    {
        // Go to STORED if skip_delivery OR if invoice already existed (can't poll)
        $alreadyExisted = str_starts_with($connectorInvoiceId, 'existing:');
        $shouldStore = $this->skip_delivery || $alreadyExisted;
        $targetState = $shouldStore ? PeppolState::STORED : PeppolState::SENT;

        $this->update([
            'state' => $targetState,
            'connector_invoice_id' => $connectorInvoiceId,
            'sent_at' => now(),
            'error_message' => null,
            'error_details' => null,
            'completed_at' => $shouldStore ? now() : null,
        ]);

        $this->logs()->create([
            'from_state' => PeppolState::SENDING->value,
            'to_state' => $targetState->value,
            'message' => $message ?? 'Successfully sent to connector',
            'actor' => 'system',
        ]);
    }

    /**
     * Mark invoice as send failed (will retry).
     */
    public function markAsSendFailed(string $errorMessage, ?array $errorDetails = null): void
    {
        $maxAttempts = config('peppol.dispatch.max_attempts', 3);
        $isFinalFailure = $this->dispatch_attempts >= $maxAttempts;

        $targetState = $isFinalFailure ? PeppolState::FAILED : PeppolState::SEND_FAILED;
        $retryDelays = config('peppol.dispatch.retry_delays', [5, 15, 60]);
        $delayMinutes = $retryDelays[$this->dispatch_attempts - 1] ?? end($retryDelays);

        $this->update([
            'state' => $targetState,
            'error_message' => substr($errorMessage, 0, 1000),
            'error_details' => $errorDetails,
            'next_retry_at' => $isFinalFailure ? null : now()->addMinutes($delayMinutes),
            'completed_at' => $isFinalFailure ? now() : null,
        ]);

        $this->logs()->create([
            'from_state' => PeppolState::SENDING->value,
            'to_state' => $targetState->value,
            'message' => $isFinalFailure
                ? "Permanently failed after {$maxAttempts} attempts: {$errorMessage}"
                : "Send failed (attempt {$this->dispatch_attempts}/{$maxAttempts}): {$errorMessage}",
            'details' => $errorDetails,
            'actor' => 'system',
        ]);
    }

    /**
     * Update delivery status from polling.
     */
    public function updateDeliveryStatus(PeppolState $newState, ?string $message = null): void
    {
        if (! in_array($newState, [
            PeppolState::POLLING,
            PeppolState::DELIVERED,
            PeppolState::ACCEPTED,
            PeppolState::REJECTED,
            PeppolState::FAILED,
            PeppolState::STORED,
        ], true)) {
            throw new InvalidArgumentException("Invalid delivery state: {$newState->value}");
        }

        $this->transitionTo($newState, $message, null, 'poll');
    }

    /**
     * Schedule next poll attempt.
     */
    public function scheduleNextPoll(): void
    {
        // Delays in minutes: 1min, 5min, 10min, 30min, 1hr, 6hr, 24hr, 7 days
        $delays = config('peppol.poll.retry_delays_minutes', [1, 5, 10, 30, 60, 360, 1440, 10080]);
        $delayMinutes = $delays[$this->poll_attempts] ?? end($delays);

        $this->update([
            'poll_attempts' => $this->poll_attempts + 1,
            'next_retry_at' => now()->addMinutes($delayMinutes),
        ]);
    }

    /**
     * Cancel the invoice.
     */
    public function cancel(?string $reason = null, ?string $actor = null): void
    {
        if ($this->state->isFinal()) {
            throw new RuntimeException('Cannot cancel invoice in final state');
        }

        $this->transitionTo(
            PeppolState::CANCELLED,
            $reason ?? 'Invoice cancelled',
            null,
            $actor ?? auth()->user()?->email ?? 'system'
        );
    }

    // =========================================================================
    // Query Scopes
    // =========================================================================

    /**
     * Invoices ready for dispatch.
     */
    public function scopeReadyToDispatch(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [PeppolState::SCHEDULED, PeppolState::SEND_FAILED])
            ->where(function (Builder $q) {
                $q->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Invoices needing status polling.
     */
    public function scopeNeedsPolling(Builder $query): Builder
    {
        $maxPollAttempts = config('peppol.poll.max_attempts', 50);

        return $query
            ->whereIn('state', [PeppolState::SENT, PeppolState::POLLING])
            ->whereNotNull('connector_invoice_id')
            ->where('skip_delivery', false)
            ->where('poll_attempts', '<', $maxPollAttempts)
            ->where(function (Builder $q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Invoices in terminal states.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('state', [
            PeppolState::DELIVERED,
            PeppolState::ACCEPTED,
            PeppolState::REJECTED,
            PeppolState::FAILED,
            PeppolState::CANCELLED,
            PeppolState::STORED,
        ]);
    }

    /**
     * Invoices in success states.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('state', [
            PeppolState::DELIVERED,
            PeppolState::ACCEPTED,
            PeppolState::STORED,
        ]);
    }

    /**
     * Invoices in failure states.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('state', [
            PeppolState::SEND_FAILED,
            PeppolState::REJECTED,
            PeppolState::FAILED,
        ]);
    }

    /**
     * Filter by state.
     */
    public function scopeInState(Builder $query, PeppolState $state): Builder
    {
        return $query->where('state', $state);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Check if the invoice can be retried.
     */
    public function canRetry(): bool
    {
        if (! $this->state->canRetryDispatch()) {
            return false;
        }

        $maxAttempts = config('peppol.dispatch.max_attempts', 3);

        return $this->dispatch_attempts < $maxAttempts;
    }

    /**
     * Check if dispatch is ready now.
     */
    public function isReadyToDispatch(): bool
    {
        if (! $this->state->isPendingDispatch()) {
            return false;
        }

        if ($this->scheduled_at && $this->scheduled_at->isFuture()) {
            return false;
        }

        if ($this->next_retry_at && $this->next_retry_at->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Check if polling is needed.
     */
    public function needsPolling(): bool
    {
        if (! $this->state->shouldPoll()) {
            return false;
        }

        if ($this->skip_delivery) {
            return false;
        }

        if (! $this->connector_invoice_id) {
            return false;
        }

        $maxAttempts = config('peppol.poll.max_attempts', 50);
        if ($this->poll_attempts >= $maxAttempts) {
            return false;
        }

        if ($this->next_retry_at && $this->next_retry_at->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Get time until next retry.
     */
    public function getTimeUntilRetry(): ?int
    {
        if (! $this->next_retry_at) {
            return null;
        }

        return max(0, $this->next_retry_at->diffInSeconds(now(), false));
    }
}
