<?php

declare(strict_types=1);

namespace Deinte\Peppol;

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
        $this->app->singleton(PeppolConnector::class, function ($app) {
            $config = config('peppol');
            $connectorName = $config['default_connector'] ?? 'scrada';
            $connectorConfig = $config['connectors'][$connectorName] ?? [];

            return match ($connectorName) {
                'scrada' => $this->createScradaConnector($connectorConfig),
                default => throw new InvalidArgumentException("Unknown PEPPOL connector: {$connectorName}"),
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

    /**
     * Create the Scrada connector with validated config.
     *
     * @param  array<string, mixed>  $config
     */
    private function createScradaConnector(array $config): ScradaConnector
    {
        $apiKey = $config['api_key'] ?? null;
        $apiSecret = $config['api_secret'] ?? null;
        $companyId = $config['company_id'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException(
                'PEPPOL Scrada connector requires api_key. Set SCRADA_API_KEY in .env'
            );
        }

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new RuntimeException(
                'PEPPOL Scrada connector requires api_secret. Set SCRADA_API_SECRET in .env'
            );
        }

        if (! is_string($companyId) || $companyId === '') {
            throw new RuntimeException(
                'PEPPOL Scrada connector requires company_id. Set SCRADA_COMPANY_ID in .env'
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
