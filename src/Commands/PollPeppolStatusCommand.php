<?php

declare(strict_types=1);

namespace Deinte\Peppol\Commands;

use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\PeppolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PollPeppolStatusCommand extends Command
{
    protected $signature = 'peppol:poll-status
                            {--dry-run : Show what would be polled without actually polling}
                            {--limit=100 : Maximum number of invoices to poll}
                            {--id= : Poll status for a specific PEPPOL invoice ID}
                            {--status : Show polling queue status only}';

    protected $description = 'Poll delivery status for dispatched PEPPOL invoices';

    public function __construct(
        private readonly PeppolService $peppolService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($invoiceId = $this->option('id')) {
            return $this->pollSingle((int) $invoiceId);
        }

        return $this->pollAll();
    }

    protected function pollAll(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $maxAttempts = config('peppol.poll.max_attempts', 5);

        $this->log('info', 'Status poll command started', [
            'dry_run' => $dryRun,
            'limit' => $limit,
            'max_attempts' => $maxAttempts,
        ]);

        // First check count for user feedback
        $totalCount = PeppolInvoice::query()
            ->needsPolling($maxAttempts)
            ->limit($limit)
            ->count();

        if ($totalCount === 0) {
            $this->info('No invoices need status polling.');
            $this->log('info', 'No invoices need status polling');

            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} invoice(s) to poll.");

        if ($dryRun) {
            $this->warn('DRY RUN - No status will actually be polled.');
            $this->newLine();
        }

        $stats = ['polled' => 0, 'updated' => 0, 'retries_scheduled' => 0, 'errors' => 0];
        $processed = 0;

        // Use chunking for memory efficiency on large datasets
        PeppolInvoice::query()
            ->needsPolling($maxAttempts)
            ->select(['id', 'invoiceable_type', 'invoiceable_id', 'connector_invoice_id', 'status', 'poll_attempts', 'next_poll_at'])
            ->with('invoiceable')
            ->chunkById(50, function ($invoices) use ($dryRun, &$stats, &$processed, $limit) {
                foreach ($invoices as $invoice) {
                    if ($processed >= $limit) {
                        return false; // Stop chunking
                    }

                    if ($dryRun) {
                        $this->outputDryRun($invoice);
                    } else {
                        $stats = $this->pollInvoice($invoice, $stats);
                    }

                    $processed++;
                }

                return $processed < $limit;
            });

        $this->outputSummary($stats, $dryRun);

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function pollInvoice(PeppolInvoice $invoice, array $stats): array
    {
        $info = "#{$invoice->id} (Connector: {$invoice->connector_invoice_id})";

        try {
            $oldStatus = $invoice->status;
            $status = $this->peppolService->getInvoiceStatus($invoice);
            $stats['polled']++;

            if ($status->status !== $oldStatus) {
                $this->outputStatusChange($info, $oldStatus, $status->status);
                $stats['updated']++;

                // If status changed from failed to something else, reset poll attempts
                if ($oldStatus === PeppolStatus::FAILED_DELIVERY && $status->status !== PeppolStatus::FAILED_DELIVERY) {
                    $invoice->resetPollAttempts();
                }
            } elseif ($status->status === PeppolStatus::FAILED_DELIVERY) {
                // Still failed - schedule retry
                $stats = $this->handleFailedInvoice($invoice, $info, $stats);
            } else {
                $this->line("  <comment>No change:</comment> {$info} - {$status->status->value}");
            }
        } catch (\Exception $e) {
            $this->line("  <error>Failed:</error> {$info} - {$e->getMessage()}");
            $stats['errors']++;

            $this->log('error', 'Failed to poll status', [
                'peppol_invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    protected function handleFailedInvoice(PeppolInvoice $invoice, string $info, array $stats): array
    {
        if ($invoice->hasExceededMaxPollAttempts()) {
            $this->line("  <comment>Max retries:</comment> {$info} - No more retries (attempt {$invoice->poll_attempts})");
        } else {
            $invoice->scheduleNextPoll();
            // Model attributes are updated in memory by scheduleNextPoll() - no fresh() needed
            $this->line("  <comment>Retry scheduled:</comment> {$info} - Next poll at {$invoice->next_poll_at->format('d/m/Y H:i')}");
            $stats['retries_scheduled']++;

            $this->log('debug', 'Retry scheduled for failed invoice', [
                'peppol_invoice_id' => $invoice->id,
                'poll_attempts' => $invoice->poll_attempts,
                'next_poll_at' => $invoice->next_poll_at,
            ]);
        }

        return $stats;
    }

    protected function pollSingle(int $invoiceId): int
    {
        $this->info("Polling status for PEPPOL invoice #{$invoiceId}");

        $invoice = PeppolInvoice::with('invoiceable')->find($invoiceId);

        if (! $invoice) {
            $this->error("PEPPOL invoice #{$invoiceId} not found.");

            return self::FAILURE;
        }

        if (! $invoice->connector_invoice_id) {
            $this->error('Invoice has not been dispatched yet (no connector invoice ID).');

            return self::FAILURE;
        }

        try {
            $oldStatus = $invoice->status;
            $status = $this->peppolService->getInvoiceStatus($invoice);

            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                [
                    ['PEPPOL Invoice ID', $invoice->id],
                    ['Connector Invoice ID', $invoice->connector_invoice_id],
                    ['Previous Status', $oldStatus->value],
                    ['Current Status', $status->status->value],
                    ['Status Changed', $status->status !== $oldStatus ? 'Yes' : 'No'],
                    ['Message', $status->message ?? '-'],
                    ['Poll Attempts', $invoice->poll_attempts],
                    ['Next Poll At', $invoice->next_poll_at?->format('d/m/Y H:i') ?? '-'],
                    ['Last Updated', $invoice->updated_at->format('d/m/Y H:i:s')],
                ]
            );

            if ($status->status !== $oldStatus) {
                $this->info("Status updated: {$oldStatus->value} -> {$status->status->value}");
            } else {
                $this->comment('No status change.');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to poll status: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function showStatus(): int
    {
        $maxAttempts = config('peppol.poll.max_attempts', 5);
        $tableName = (new PeppolInvoice)->getTable();

        // Use single aggregate query to reduce from 7 queries to 1
        $stats = DB::table($tableName)
            ->selectRaw('
                SUM(CASE WHEN status = ? AND dispatched_at IS NOT NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = ? AND poll_attempts < ? THEN 1 ELSE 0 END) as failed_retryable,
                SUM(CASE WHEN status = ? AND poll_attempts >= ? THEN 1 ELSE 0 END) as failed_final
            ', [
                PeppolStatus::PENDING->value,
                PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION->value,
                PeppolStatus::ACCEPTED->value,
                PeppolStatus::REJECTED->value,
                PeppolStatus::FAILED_DELIVERY->value,
                $maxAttempts,
                PeppolStatus::FAILED_DELIVERY->value,
                $maxAttempts,
            ])
            ->first();

        // needsPolling uses complex scope logic, keep as separate optimized count
        $needsPolling = PeppolInvoice::query()->needsPolling($maxAttempts)->count();

        $this->info('PEPPOL Invoice Status Overview');
        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Needs polling now', $needsPolling],
                ['Pending (dispatched)', (int) $stats->pending],
                ['Delivered (awaiting confirmation)', (int) $stats->delivered],
                ['Accepted (final)', (int) $stats->accepted],
                ['Rejected (final)', (int) $stats->rejected],
                ['Failed (retryable)', (int) $stats->failed_retryable],
                ['Failed (max retries)', (int) $stats->failed_final],
            ]
        );

        if ($needsPolling > 0) {
            $this->newLine();
            $this->comment("Run 'php artisan peppol:poll-status' to poll {$needsPolling} invoice(s)");
        }

        return self::SUCCESS;
    }

    protected function outputDryRun(PeppolInvoice $invoice): void
    {
        $retryInfo = $invoice->status === PeppolStatus::FAILED_DELIVERY
            ? " (retry {$invoice->poll_attempts})"
            : '';

        $this->line("  Would poll: #{$invoice->id} - Current: {$invoice->status->value}{$retryInfo}");
    }

    protected function outputStatusChange(string $info, PeppolStatus $old, PeppolStatus $new): void
    {
        $this->line("  <info>Updated:</info> {$info} - {$old->value} -> {$new->value}");

        $this->log('info', 'Invoice status updated', [
            'info' => $info,
            'old_status' => $old->value,
            'new_status' => $new->value,
        ]);
    }

    protected function outputSummary(array $stats, bool $dryRun): void
    {
        $this->newLine();

        if (! $dryRun) {
            $this->info("Polled: {$stats['polled']}");
            $this->info("Updated: {$stats['updated']}");
            if ($stats['retries_scheduled'] > 0) {
                $this->comment("Retries scheduled: {$stats['retries_scheduled']}");
            }
            if ($stats['errors'] > 0) {
                $this->error("Errors: {$stats['errors']}");
            }

            $this->log('info', 'Status poll command completed', $stats);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[PollStatusCommand] {$message}", $context);
    }
}
