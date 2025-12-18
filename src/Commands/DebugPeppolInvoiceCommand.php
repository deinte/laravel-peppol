<?php

declare(strict_types=1);

namespace Deinte\Peppol\Commands;

use Deinte\Peppol\Connectors\ScradaConnector;
use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Console\Command;

class DebugPeppolInvoiceCommand extends Command
{
    protected $signature = 'peppol:debug
                            {id : PEPPOL Invoice ID}
                            {--payload : Show stored request payload if available}
                            {--logs : Show activity logs}';

    protected $description = 'Debug a PEPPOL invoice - show state, details, and optionally stored payload';

    public function handle(): int
    {
        $id = $this->argument('id');

        $peppolInvoice = PeppolInvoice::with(['recipientCompany', 'logs', 'invoiceable'])->find($id);

        if (! $peppolInvoice) {
            $this->error("PEPPOL Invoice #{$id} not found.");

            return self::FAILURE;
        }

        $this->showInvoiceDetails($peppolInvoice);

        if ($this->option('payload')) {
            $this->showPayload($peppolInvoice);
        }

        if ($this->option('logs')) {
            $this->showLogs($peppolInvoice);
        }

        return self::SUCCESS;
    }

    private function showInvoiceDetails(PeppolInvoice $invoice): void
    {
        $this->info('PEPPOL Invoice Details');
        $this->newLine();

        $this->components->twoColumnDetail('ID', (string) $invoice->id);
        $this->components->twoColumnDetail('State', $invoice->state->label());
        $this->components->twoColumnDetail('Connector Invoice ID', $invoice->connector_invoice_id ?? 'N/A');
        $this->components->twoColumnDetail('Connector Type', $invoice->connector_type);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Invoiceable</>', '');
        $this->components->twoColumnDetail('Type', class_basename($invoice->invoiceable_type));
        $this->components->twoColumnDetail('ID', (string) $invoice->invoiceable_id);

        if ($invoice->invoiceable) {
            // Try common invoice properties
            if (method_exists($invoice->invoiceable, 'invoice_number_display')) {
                $this->components->twoColumnDetail('Number', $invoice->invoiceable->invoice_number_display);
            } elseif (isset($invoice->invoiceable->number)) {
                $this->components->twoColumnDetail('Number', $invoice->invoiceable->number);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Recipient</>', '');
        if ($invoice->recipientCompany) {
            $this->components->twoColumnDetail('Name', $invoice->recipientCompany->name ?? 'N/A');
            $this->components->twoColumnDetail('VAT Number', $invoice->recipientCompany->vat_number ?? 'N/A');
            $this->components->twoColumnDetail('PEPPOL ID', $invoice->recipientCompany->peppol_id ?? 'N/A');
            $this->components->twoColumnDetail('On PEPPOL', $invoice->recipientCompany->isOnPeppol() ? 'Yes' : 'No');
        } else {
            $this->components->twoColumnDetail('Company', 'Not linked');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Timing</>', '');
        $this->components->twoColumnDetail('Scheduled At', $invoice->scheduled_at?->format('Y-m-d H:i:s') ?? 'N/A');
        $this->components->twoColumnDetail('Sent At', $invoice->sent_at?->format('Y-m-d H:i:s') ?? 'N/A');
        $this->components->twoColumnDetail('Completed At', $invoice->completed_at?->format('Y-m-d H:i:s') ?? 'N/A');
        $this->components->twoColumnDetail('Next Retry At', $invoice->next_retry_at?->format('Y-m-d H:i:s') ?? 'N/A');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Attempts</>', '');
        $this->components->twoColumnDetail('Dispatch Attempts', (string) $invoice->dispatch_attempts);
        $this->components->twoColumnDetail('Poll Attempts', (string) $invoice->poll_attempts);
        $this->components->twoColumnDetail('Skip Delivery', $invoice->skip_delivery ? 'Yes' : 'No');

        if ($invoice->error_message) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=red>Error</>', '');
            $this->components->twoColumnDetail('Message', $invoice->error_message);

            if ($invoice->error_details) {
                $this->line('Error Details:');
                $this->line(json_encode($invoice->error_details, JSON_PRETTY_PRINT));
            }
        }
    }

    private function showPayload(PeppolInvoice $invoice): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Request Payload</>', '');

        if (isset($invoice->request_payload) && $invoice->request_payload) {
            $this->line(json_encode($invoice->request_payload, JSON_PRETTY_PRINT));
        } else {
            $this->warn('No request payload stored. Payload is only stored if the column exists and was populated during dispatch.');
        }
    }

    private function showLogs(PeppolInvoice $invoice): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Activity Logs</>', '');

        if ($invoice->logs->isEmpty()) {
            $this->warn('No activity logs found.');

            return;
        }

        $headers = ['Date', 'Transition', 'Message', 'Actor'];
        $rows = [];

        foreach ($invoice->logs as $log) {
            $rows[] = [
                $log->created_at->format('Y-m-d H:i:s'),
                "{$log->from_state} → {$log->to_state}",
                mb_substr($log->message ?? '-', 0, 50),
                $log->actor ?? '-',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Preview what would be sent to the connector.
     * Call this from your app with the Invoice DTO.
     */
    public static function previewConnectorPayload(Invoice $invoice, PeppolConnector $connector): array
    {
        if ($connector instanceof ScradaConnector) {
            $scradaData = $connector->transformInvoiceToScradaFormat($invoice);

            return [
                'connector' => 'scrada',
                'invoice_dto' => $invoice->toArray(),
                'connector_payload' => $scradaData->toArray(),
            ];
        }

        return [
            'connector' => get_class($connector),
            'invoice_dto' => $invoice->toArray(),
            'connector_payload' => 'Preview not available for this connector',
        ];
    }
}
