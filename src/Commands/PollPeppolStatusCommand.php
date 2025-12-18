<?php

declare(strict_types=1);

namespace Deinte\Peppol\Commands;

use Deinte\Peppol\Enums\PeppolState;
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\PeppolService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PollPeppolStatusCommand extends Command
{
    protected $signature = 'peppol:poll-status
                            {--dry-run : Show what would be polled without actually polling}
                            {--force : Ignore next_retry_at schedule and poll immediately}
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
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        $this->log('info', 'Status poll command started', [
            'dry_run' => $dryRun,
            'force' => $force,
            'limit' => $limit,
        ]);

        // Build query - use force to ignore schedule
        $query = $force
            ? $this->buildForceQuery()
            : PeppolInvoice::query()->needsPolling();

        $totalCount = (clone $query)->limit($limit)->count();

        if ($totalCount === 0) {
            $this->info('No invoices need status polling.');
            $this->log('info', 'No invoices need status polling');

            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} invoice(s) to poll.");

        if ($force) {
            $this->warn('FORCE MODE - Ignoring next_retry_at schedule.');
        }

        if ($dryRun) {
            $this->warn('DRY RUN - No status will actually be polled.');
            $this->newLine();
        }

        $stats = ['polled' => 0, 'updated' => 0, 'errors' => 0];
        $processed = 0;

        // Use chunking for memory efficiency on large datasets
        $query
            ->select(['id', 'invoiceable_type', 'invoiceable_id', 'connector_invoice_id', 'state', 'poll_attempts', 'next_retry_at'])
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
            $oldState = $invoice->state;
            $status = $this->peppolService->getInvoiceStatus($invoice);
            $stats['polled']++;

            // Refresh to get updated state
            $invoice->refresh();
            $newState = $invoice->state;

            if ($newState !== $oldState) {
                $this->outputStateChange($info, $oldState, $newState);
                $stats['updated']++;
            } else {
                $this->line("  <comment>No change:</comment> {$info} - {$newState->label()}");
            }
        } catch (Exception $e) {
            $this->line("  <error>Failed:</error> {$info} - {$e->getMessage()}");
            $stats['errors']++;

            $this->log('error', 'Failed to poll status', [
                'peppol_invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
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
            $oldState = $invoice->state;
            $status = $this->peppolService->getInvoiceStatus($invoice);

            // Refresh to get updated state
            $invoice->refresh();

            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                [
                    ['PEPPOL Invoice ID', $invoice->id],
                    ['Connector Invoice ID', $invoice->connector_invoice_id],
                    ['Previous State', $oldState->label()],
                    ['Current State', $invoice->state->label()],
                    ['State Changed', $invoice->state !== $oldState ? 'Yes' : 'No'],
                    ['Connector Status', $status->status->value],
                    ['Message', $status->message ?? '-'],
                    ['Poll Attempts', $invoice->poll_attempts],
                    ['Next Poll At', $invoice->next_retry_at?->format('d/m/Y H:i') ?? '-'],
                    ['Sent At', $invoice->sent_at?->format('d/m/Y H:i:s') ?? '-'],
                    ['Completed At', $invoice->completed_at?->format('d/m/Y H:i:s') ?? '-'],
                ]
            );

            if ($invoice->state !== $oldState) {
                $this->info("State updated: {$oldState->label()} -> {$invoice->state->label()}");
            } else {
                $this->comment('No state change.');
            }

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to poll status: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    protected function showStatus(): int
    {
        $maxPollAttempts = config('peppol.poll.max_attempts', 50);
        $tableName = (new PeppolInvoice)->getTable();

        $needsPolling = PeppolState::needsPollingValues();
        $awaitingDelivery = PeppolState::awaitingDeliveryValues();

        // Use single aggregate query for efficiency
        $stats = DB::table($tableName)
            ->selectRaw('
                SUM(CASE WHEN state IN (?, ?) AND connector_invoice_id IS NOT NULL AND skip_delivery = 0 AND poll_attempts < ? THEN 1 ELSE 0 END) as needs_polling,
                SUM(CASE WHEN state IN (?, ?) THEN 1 ELSE 0 END) as awaiting_delivery,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as stored,
                SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as cancelled
            ', [
                ...$needsPolling,
                $maxPollAttempts,
                ...$awaitingDelivery,
                PeppolState::DELIVERED->value,
                PeppolState::ACCEPTED->value,
                PeppolState::REJECTED->value,
                PeppolState::FAILED->value,
                PeppolState::STORED->value,
                PeppolState::CANCELLED->value,
            ])
            ->first();

        $this->info('PEPPOL Invoice Status Overview');
        $this->newLine();

        $this->table(
            ['State', 'Count'],
            [
                ['Needs polling now', (int) $stats->needs_polling],
                ['Awaiting delivery (sent/polling)', (int) $stats->awaiting_delivery],
                ['Delivered', (int) $stats->delivered],
                ['Accepted', (int) $stats->accepted],
                ['Rejected', (int) $stats->rejected],
                ['Failed', (int) $stats->failed],
                ['Stored (skip_delivery)', (int) $stats->stored],
                ['Cancelled', (int) $stats->cancelled],
            ]
        );

        if ($stats->needs_polling > 0) {
            $this->newLine();
            $this->comment("Run 'php artisan peppol:poll-status' to poll {$stats->needs_polling} invoice(s)");
        }

        return self::SUCCESS;
    }

    protected function outputDryRun(PeppolInvoice $invoice): void
    {
        $attemptInfo = $invoice->poll_attempts > 0
            ? " (attempt #{$invoice->poll_attempts})"
            : '';

        $this->line("  Would poll: #{$invoice->id} - State: {$invoice->state->label()}{$attemptInfo}");
    }

    protected function outputStateChange(string $info, PeppolState $old, PeppolState $new): void
    {
        $this->line("  <info>Updated:</info> {$info} - {$old->label()} -> {$new->label()}");

        $this->log('info', 'Invoice state updated', [
            'info' => $info,
            'old_state' => $old->value,
            'new_state' => $new->value,
        ]);
    }

    protected function outputSummary(array $stats, bool $dryRun): void
    {
        $this->newLine();

        if (! $dryRun) {
            $this->info("Polled: {$stats['polled']}");
            $this->info("Updated: {$stats['updated']}");
            if ($stats['errors'] > 0) {
                $this->error("Errors: {$stats['errors']}");
            }

            $this->log('info', 'Status poll command completed', $stats);
        }
    }

    /**
     * Build query for force mode (ignores next_retry_at schedule).
     */
    private function buildForceQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $maxPollAttempts = config('peppol.poll.max_attempts', 50);

        return PeppolInvoice::query()
            ->whereIn('state', [PeppolState::SENT, PeppolState::POLLING])
            ->whereNotNull('connector_invoice_id')
            ->where('skip_delivery', false)
            ->where('poll_attempts', '<', $maxPollAttempts);
        // Note: intentionally omits next_retry_at check
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[PollStatusCommand] {$message}", $context);
    }
}
