<?php

declare(strict_types=1);

namespace Deinte\Peppol\Commands;

use Deinte\Peppol\Enums\PeppolState;
use Deinte\Peppol\Jobs\DispatchPeppolInvoice;
use Deinte\Peppol\Models\PeppolInvoice;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchPeppolInvoicesCommand extends Command
{
    protected $signature = 'peppol:dispatch-invoices
                            {--dry-run : Show what would be dispatched without actually dispatching}
                            {--limit=50 : Maximum number of invoices to dispatch}
                            {--no-lock : Skip distributed lock (use with caution)}
                            {--status : Show dispatch queue status only}';

    protected $description = 'Dispatch scheduled PEPPOL invoices that are due';

    /**
     * Lock timeout in seconds (10 minutes).
     */
    private const LOCK_TIMEOUT = 600;

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('no-lock')) {
            return $this->dispatchInvoices();
        }

        $lock = Cache::lock('peppol:dispatch-invoices', self::LOCK_TIMEOUT);

        if (! $lock->get()) {
            $this->warn('Another dispatch process is already running. Use --no-lock to override.');
            $this->log('warning', 'Dispatch skipped - lock held by another process');

            return self::SUCCESS;
        }

        try {
            return $this->dispatchInvoices();
        } finally {
            $lock->release();
        }
    }

    protected function dispatchInvoices(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->log('info', 'Dispatch command started', [
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        // Use the readyToDispatch scope which handles state and timing
        $totalCount = PeppolInvoice::query()
            ->readyToDispatch()
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
            ->select(['id', 'invoiceable_type', 'invoiceable_id', 'recipient_peppol_company_id', 'state', 'dispatch_attempts', 'scheduled_at'])
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
        $attemptInfo = $invoice->dispatch_attempts > 0 ? " (retry #{$invoice->dispatch_attempts})" : '';
        $info = "#{$invoice->id} - {$invoiceableType} #{$invoice->invoiceable_id}{$attemptInfo}";

        try {
            DispatchPeppolInvoice::dispatch($invoice->id);
            $this->line("  <info>Dispatched:</info> {$info}");
            $stats['dispatched']++;

            $this->log('debug', 'Invoice dispatched to queue', [
                'peppol_invoice_id' => $invoice->id,
                'invoiceable_type' => $invoice->invoiceable_type,
                'invoiceable_id' => $invoice->invoiceable_id,
                'state' => $invoice->state->value,
                'dispatch_attempts' => $invoice->dispatch_attempts,
            ]);
        } catch (Exception $e) {
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

        $pendingDispatch = PeppolState::pendingDispatchValues();
        $sent = PeppolState::awaitingDeliveryValues();
        $completed = PeppolState::successValues();

        // Use single aggregate query for efficiency
        $stats = DB::table($tableName)
            ->selectRaw('
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as send_failed,
                SUM(CASE WHEN state IN (?, ?) AND (scheduled_at IS NULL OR scheduled_at <= ?) AND (next_retry_at IS NULL OR next_retry_at <= ?) THEN 1 ELSE 0 END) as due_now,
                SUM(CASE WHEN state = ? AND scheduled_at > ? THEN 1 ELSE 0 END) as scheduled_later,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as sending,
                SUM(CASE WHEN state IN (?, ?) THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN state IN (?, ?, ?) THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as failed
            ', [
                PeppolState::SCHEDULED->value,
                PeppolState::SEND_FAILED->value,
                ...$pendingDispatch,
                $now,
                $now,
                PeppolState::SCHEDULED->value,
                $now,
                PeppolState::SENDING->value,
                ...$sent,
                ...$completed,
                PeppolState::FAILED->value,
            ])
            ->first();

        $this->info('PEPPOL Invoice Dispatch Status');
        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Due for dispatch now', (int) $stats->due_now],
                ['Scheduled for later', (int) $stats->scheduled_later],
                ['Awaiting retry (send_failed)', (int) $stats->send_failed],
                ['Currently sending', (int) $stats->sending],
                ['Sent (awaiting delivery)', (int) $stats->sent],
                ['Completed (delivered/accepted)', (int) $stats->completed],
                ['Permanently failed', (int) $stats->failed],
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
        $stateInfo = $invoice->state === PeppolState::SEND_FAILED
            ? " (retry #{$invoice->dispatch_attempts})"
            : '';

        $this->line("  Would dispatch: #{$invoice->id} - {$invoiceableType} #{$invoice->invoiceable_id}{$stateInfo}");
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
