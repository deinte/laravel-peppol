<?php

declare(strict_types=1);

namespace Deinte\Peppol\Commands;

use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Jobs\DispatchPeppolInvoice;
use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchPeppolInvoicesCommand extends Command
{
    protected $signature = 'peppol:dispatch-invoices
                            {--dry-run : Show what would be dispatched without actually dispatching}
                            {--limit=50 : Maximum number of invoices to dispatch}
                            {--status : Show dispatch queue status only}';

    protected $description = 'Dispatch scheduled PEPPOL invoices that are due';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        return $this->dispatchInvoices();
    }

    protected function dispatchInvoices(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->log('info', 'Dispatch command started', [
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        // First check count for user feedback
        $totalCount = PeppolInvoice::query()
            ->readyToDispatch()
            ->where('status', PeppolStatus::PENDING)
            ->limit($limit)
            ->count();

        if ($totalCount === 0) {
            $this->info('No invoices due for dispatch.');
            $this->log('info', 'No invoices due for dispatch');

            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} invoice(s) due for dispatch.");

        if ($dryRun) {
            $this->warn('DRY RUN - No invoices will actually be dispatched.');
            $this->newLine();
        }

        $stats = ['dispatched' => 0, 'errors' => 0];
        $processed = 0;

        // Use chunking for memory efficiency on large datasets
        PeppolInvoice::query()
            ->readyToDispatch()
            ->where('status', PeppolStatus::PENDING)
            ->select(['id', 'invoiceable_type', 'invoiceable_id', 'recipient_peppol_company_id', 'scheduled_dispatch_at'])
            ->with(['invoiceable', 'recipientCompany'])
            ->chunkById(50, function ($invoices) use ($dryRun, &$stats, &$processed, $limit) {
                foreach ($invoices as $invoice) {
                    if ($processed >= $limit) {
                        return false; // Stop chunking
                    }

                    if ($dryRun) {
                        $this->outputDryRun($invoice);
                    } else {
                        $stats = $this->dispatchInvoice($invoice, $stats);
                    }

                    $processed++;
                }

                return $processed < $limit;
            });

        $this->outputSummary($stats, $dryRun);

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function dispatchInvoice(PeppolInvoice $invoice, array $stats): array
    {
        $invoiceableType = class_basename($invoice->invoiceable_type);
        $info = "#{$invoice->id} - {$invoiceableType} #{$invoice->invoiceable_id}";

        try {
            DispatchPeppolInvoice::dispatch($invoice->id);
            $this->line("  <info>Dispatched:</info> {$info}");
            $stats['dispatched']++;

            $this->log('debug', 'Invoice dispatched to queue', [
                'peppol_invoice_id' => $invoice->id,
                'invoiceable_type' => $invoice->invoiceable_type,
                'invoiceable_id' => $invoice->invoiceable_id,
            ]);
        } catch (\Exception $e) {
            $this->line("  <error>Failed:</error> {$info} - {$e->getMessage()}");
            $stats['errors']++;

            $this->log('error', 'Failed to dispatch invoice', [
                'peppol_invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    protected function showStatus(): int
    {
        $tableName = (new PeppolInvoice)->getTable();
        $now = now();

        // Use single aggregate query to reduce from 5 queries to 1
        $stats = DB::table($tableName)
            ->selectRaw('
                SUM(CASE WHEN dispatched_at IS NULL AND status = ? THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN dispatched_at IS NULL AND status = ? AND scheduled_dispatch_at IS NOT NULL AND scheduled_dispatch_at <= ? THEN 1 ELSE 0 END) as due_now,
                SUM(CASE WHEN dispatched_at IS NULL AND status = ? AND scheduled_dispatch_at > ? THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN dispatched_at IS NOT NULL THEN 1 ELSE 0 END) as dispatched,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed
            ', [
                PeppolStatus::PENDING->value,
                PeppolStatus::PENDING->value,
                $now,
                PeppolStatus::PENDING->value,
                $now,
                PeppolStatus::FAILED_DELIVERY->value,
            ])
            ->first();

        $this->info('PEPPOL Invoice Dispatch Status');
        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Due for dispatch now', (int) $stats->due_now],
                ['Scheduled for later', (int) $stats->scheduled],
                ['Total pending', (int) $stats->pending],
                ['Already dispatched', (int) $stats->dispatched],
                ['Failed delivery', (int) $stats->failed],
            ]
        );

        if ($stats->due_now > 0) {
            $this->newLine();
            $this->comment("Run 'php artisan peppol:dispatch-invoices' to dispatch {$stats->due_now} invoice(s)");
        }

        return self::SUCCESS;
    }

    protected function outputDryRun(PeppolInvoice $invoice): void
    {
        $invoiceableType = class_basename($invoice->invoiceable_type);
        $this->line("  Would dispatch: #{$invoice->id} - {$invoiceableType} #{$invoice->invoiceable_id}");
    }

    protected function outputSummary(array $stats, bool $dryRun): void
    {
        $this->newLine();

        if (! $dryRun) {
            $this->info("Dispatched: {$stats['dispatched']}");
            if ($stats['errors'] > 0) {
                $this->error("Errors: {$stats['errors']}");
            }

            $this->log('info', 'Dispatch command completed', $stats);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[DispatchCommand] {$message}", $context);
    }
}
