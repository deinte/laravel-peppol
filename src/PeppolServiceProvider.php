<?php

declare(strict_types=1);

namespace Deinte\Peppol;

use Deinte\Peppol\Commands\DispatchPeppolInvoicesCommand;
use Deinte\Peppol\Commands\PollPeppolStatusCommand;
use Deinte\Peppol\Connectors\ScradaConnector;
use Deinte\Peppol\Contracts\PeppolConnector;
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
            ]);
    }

    public function packageRegistered(): void
    {
        // Bind the connector interface to the configured implementation
        $this->app->singleton(PeppolConnector::class, function ($app) {
            $config = config('peppol');
            $connectorName = $config['default_connector'] ?? 'scrada';
            $connectorConfig = $config['connectors'][$connectorName] ?? [];

            return match ($connectorName) {
                'scrada' => new ScradaConnector(
                    apiKey: $connectorConfig['api_key'],
                    apiSecret: $connectorConfig['api_secret'] ?? '',
                    companyId: $connectorConfig['company_id'] ?? '',
                    baseUrl: $connectorConfig['base_url'] ?? null,
                ),
                default => throw new \InvalidArgumentException("Unknown PEPPOL connector: {$connectorName}"),
            };
        });

        // Bind the main service
        $this->app->singleton(PeppolService::class, function ($app) {
            return new PeppolService(
                connector: $app->make(PeppolConnector::class),
            );
        });

        // Alias for facade
        $this->app->alias(PeppolService::class, 'peppol');
    }
}
