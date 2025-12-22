<?php

declare(strict_types=1);

namespace Deinte\Peppol\Jobs;

use Deinte\Peppol\Contracts\InvoiceTransformer;
use Deinte\Peppol\Events\InvoiceDispatched;
use Deinte\Peppol\Events\InvoiceFailed;
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\PeppolService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches a PEPPOL invoice to the connector.
 *
 * This job handles the transformation and dispatch of an invoice.
 * Retry logic is managed by the PeppolInvoice model (dispatch_attempts).
 */
class DispatchPeppolInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job retries are handled by the model's dispatch_attempts.
     * This job should only run once per dispatch.
     */
    public int $tries = 1;

    /**
     * Job timeout in seconds.
     * Scrada API timeout is 30s, add buffer for transformation and logging.
     */
    public int $timeout = 120;

    public function __construct(
        public readonly int $peppolInvoiceId,
    ) {
        $this->onQueue(config('peppol.dispatch.queue', 'default'));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[DispatchPeppolInvoice] {$message}", $context);
    }

    public function handle(PeppolService $service): void
    {
        $this->log('info', 'Job started', [
            'peppol_invoice_id' => $this->peppolInvoiceId,
        ]);

        $peppolInvoice = PeppolInvoice::find($this->peppolInvoiceId);

        if (! $peppolInvoice) {
            $this->log('error', 'PEPPOL invoice not found', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
            ]);

            return;
        }

        // Skip if already dispatched (has connector ID and is not in a retryable state)
        if ($peppolInvoice->connector_invoice_id && ! $peppolInvoice->state->canRetryDispatch()) {
            $this->log('info', 'Invoice already dispatched - skipping', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'state' => $peppolInvoice->state->value,
                'connector_invoice_id' => $peppolInvoice->connector_invoice_id,
            ]);

            return;
        }

        // Skip if not ready to dispatch (future scheduled_at or retry time)
        if (! $peppolInvoice->isReadyToDispatch()) {
            $this->log('info', 'Invoice not ready for dispatch - skipping', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'state' => $peppolInvoice->state->value,
                'scheduled_at' => $peppolInvoice->scheduled_at?->toIso8601String(),
                'next_retry_at' => $peppolInvoice->next_retry_at?->toIso8601String(),
            ]);

            return;
        }

        // Load the invoiceable model
        $invoice = $peppolInvoice->invoiceable;

        if (! $invoice) {
            $this->log('error', 'Invoiceable model not found', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'invoiceable_type' => $peppolInvoice->invoiceable_type,
                'invoiceable_id' => $peppolInvoice->invoiceable_id,
            ]);

            return;
        }

        $this->log('debug', 'Invoiceable model loaded', [
            'peppol_invoice_id' => $this->peppolInvoiceId,
            'invoiceable_type' => $peppolInvoice->invoiceable_type,
            'invoiceable_id' => $peppolInvoice->invoiceable_id,
            'dispatch_attempt' => $peppolInvoice->dispatch_attempts + 1,
        ]);

        try {
            // Transform the invoice using the app's transformer
            $this->log('debug', 'Transforming invoice to PEPPOL format', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
            ]);

            $transformer = app(InvoiceTransformer::class);
            $invoiceData = $transformer->toPeppolInvoice($invoice);

            $this->log('debug', 'Invoice transformed successfully', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'invoice_number' => $invoiceData->invoiceNumber,
                'recipient_vat' => $invoiceData->recipientVatNumber,
                'total_amount' => $invoiceData->totalAmount,
                'line_items_count' => count($invoiceData->lineItems),
            ]);

            // Dispatch via service (handles state transitions internally)
            $this->log('info', 'Dispatching invoice via PeppolService', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
            ]);

            $status = $service->dispatchInvoice($peppolInvoice, $invoiceData);

            // Fire success event
            if (config('peppol.events.invoice_dispatched', true)) {
                event(new InvoiceDispatched($peppolInvoice));
            }

            $this->log('info', 'Job completed successfully', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'connector_invoice_id' => $status->connectorInvoiceId,
                'status' => $status->status->value,
                'state' => $peppolInvoice->fresh()->state->value,
            ]);
        } catch (Exception $e) {
            // State transition (send_failed or failed) is handled by PeppolService
            $peppolInvoice->refresh();

            $this->log('error', 'Job failed', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'dispatch_attempts' => $peppolInvoice->dispatch_attempts,
                'state' => $peppolInvoice->state->value,
                'can_retry' => $peppolInvoice->canRetry(),
                'next_retry_at' => $peppolInvoice->next_retry_at?->toIso8601String(),
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            // Fire failure event
            if (config('peppol.events.invoice_failed', true)) {
                event(new InvoiceFailed($peppolInvoice, $e->getMessage()));
            }

            // Don't re-throw - the model tracks state and will be picked up
            // by the dispatch command for retry if canRetry() is true
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->log('error', 'Job permanently failed', [
            'peppol_invoice_id' => $this->peppolInvoiceId,
            'error' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
