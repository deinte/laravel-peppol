<?php

declare(strict_types=1);

namespace Deinte\Peppol\Jobs;

use Deinte\Peppol\Contracts\InvoiceTransformer;
use Deinte\Peppol\Events\InvoiceDispatched;
use Deinte\Peppol\Events\InvoiceFailed;
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\PeppolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a PEPPOL invoice to the network.
 */
class DispatchPeppolInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        $peppolInvoice = PeppolInvoice::find($this->peppolInvoiceId);

        if (! $peppolInvoice) {
            $this->log('error', 'PEPPOL invoice not found', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
            ]);

            return;
        }

        // Skip if already dispatched
        if ($peppolInvoice->dispatched_at) {
            $this->log('info', 'Invoice already dispatched - skipping', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'dispatched_at' => $peppolInvoice->dispatched_at->toIso8601String(),
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

            // Dispatch via service
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
                'attempt' => $this->attempts(),
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Job failed', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'trace' => $e->getTraceAsString(),
            ]);

            // Fire failure event
            if (config('peppol.events.invoice_failed', true)) {
                event(new InvoiceFailed($peppolInvoice, $e->getMessage()));
            }

            // Re-throw to trigger retry
            throw $e;
        }
    }

    public function backoff(): array
    {
        $retryDelay = config('peppol.dispatch.retry_delay_minutes', 60);

        return [$retryDelay * 60, $retryDelay * 120, $retryDelay * 180];
    }

    public function failed(?\Throwable $exception): void
    {
        $this->log('error', 'Job permanently failed after all retries', [
            'peppol_invoice_id' => $this->peppolInvoiceId,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
