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

    public function handle(PeppolService $service): void
    {
        $peppolInvoice = PeppolInvoice::find($this->peppolInvoiceId);

        if (! $peppolInvoice) {
            Log::error('PEPPOL invoice not found', ['id' => $this->peppolInvoiceId]);

            return;
        }

        // Skip if already dispatched
        if ($peppolInvoice->dispatched_at) {
            Log::info('PEPPOL invoice already dispatched', ['id' => $this->peppolInvoiceId]);

            return;
        }

        // Load the invoiceable model
        $invoice = $peppolInvoice->invoiceable;

        if (! $invoice) {
            Log::error('Invoiceable model not found', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'invoiceable_type' => $peppolInvoice->invoiceable_type,
                'invoiceable_id' => $peppolInvoice->invoiceable_id,
            ]);

            return;
        }

        try {
            // Transform the invoice using the app's transformer
            $transformer = app(InvoiceTransformer::class);
            $invoiceData = $transformer->toPeppolInvoice($invoice);

            // Dispatch via service
            $status = $service->dispatchInvoice($peppolInvoice, $invoiceData);

            // Fire success event
            if (config('peppol.events.invoice_dispatched', true)) {
                event(new InvoiceDispatched($peppolInvoice));
            }

            Log::info('PEPPOL invoice dispatched successfully', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'connector_invoice_id' => $status->connectorInvoiceId,
                'status' => $status->status->value,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch PEPPOL invoice', [
                'peppol_invoice_id' => $this->peppolInvoiceId,
                'error' => $e->getMessage(),
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
}
