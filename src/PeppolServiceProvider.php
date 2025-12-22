<?php

declare(strict_types=1);

namespace Deinte\Peppol;

use Deinte\Peppol\Commands\DebugPeppolInvoiceCommand;
use Deinte\Peppol\Commands\DispatchPeppolInvoicesCommand;
use Deinte\Peppol\Commands\PollPeppolStatusCommand;
use Deinte\Peppol\Connectors\ScradaConnector;
use Deinte\Peppol\Contracts\PeppolConnector;
use InvalidArgumentException;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PeppolServiceProvider extends PackageServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Register peppol logging channel if not already configured
        if (! config('logging.channels.peppol')) {
            config([
                'logging.channels.peppol' => [
                    'driver' => 'single',
                    'path' => storage_path('logs/peppol.log'),
                    'level' => env('PEPPOL_LOG_LEVEL', 'debug'),
                ],
            ]);
        }
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('peppol')
            ->hasConfigFile()
            ->hasCommands([
                DebugPeppolInvoiceCommand::class,
                DispatchPeppolInvoicesCommand::class,
                PollPeppolStatusCommand::class,
            ])
            ->hasMigrations([
                'create_peppol_companies_table',
                'create_peppol_invoices_table',
                'create_peppol_invoice_statuses_table',
                'add_tax_number_fields_to_peppol_companies_table',
                'add_skip_peppol_delivery_to_peppol_invoices_table',
                'add_connector_tracking_to_peppol_invoices_table',
                'add_poll_retry_fields_to_peppol_invoices_table',
                'add_polling_indexes_to_peppol_invoices_table',
                'add_payload_columns_to_peppol_invoices_table',
                'simplify_peppol_schema',
            ]);
    }

    public function packageRegistered(): void
    {
        // Bind the connector interface to the configured implementation
        // Uses a closure so the connector is only created when actually resolved
        $this->app->singleton(PeppolConnector::class, function ($app) {
            $config = config('peppol');

            // If config isn't loaded yet (during package discovery), throw helpful error
            if (empty($config)) {
                throw new RuntimeException(
                    'PEPPOL config not loaded. Ensure the config file is published.'
                );
            }

            $connectorName = $config['default_connector'] ?? 'scrada';
            $connectorConfig = $config['connectors'][$connectorName] ?? [];

            return match ($connectorName) {
                'scrada' => $this->createScradaConnector($connectorConfig),
                default => throw new InvalidArgumentException("Unknown PEPPOL connector: {$connectorName}"),
            };
        });

        // Bind the main service - also lazy, only created when resolved
        $this->app->singleton(PeppolService::class, function ($app) {
            return new PeppolService(
                connector: $app->make(PeppolConnector::class),
            );
        });

        // Alias for facade
        $this->app->alias(PeppolService::class, 'peppol');
    }

    /**
     * Create the Scrada connector with validated config.
     *
     * Validation is deferred - credentials are only checked when the connector
     * is actually resolved from the container, not during package discovery.
     *
     * @param  array<string, mixed>  $config
     */
    private function createScradaConnector(array $config): ScradaConnector
    {
        $apiKey = $config['api_key'] ?? '';
        $apiSecret = $config['api_secret'] ?? '';
        $companyId = $config['company_id'] ?? '';

        // Validate required credentials
        $missing = [];
        if (empty($apiKey)) {
            $missing[] = 'SCRADA_API_KEY';
        }
        if (empty($apiSecret)) {
            $missing[] = 'SCRADA_API_SECRET';
        }
        if (empty($companyId)) {
            $missing[] = 'SCRADA_COMPANY_ID';
        }

        if (! empty($missing)) {
            throw new RuntimeException(
                'Missing required Scrada configuration: '.implode(', ', $missing).'. '
                .'Please set these environment variables.'
            );
        }

        return new ScradaConnector(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            companyId: $companyId,
            baseUrl: is_string($config['base_url'] ?? null) ? $config['base_url'] : null,
        );
    }
}
