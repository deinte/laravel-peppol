<?php

declare(strict_types=1);

namespace Deinte\Peppol;

use Deinte\Peppol\Connectors\ScradaConnector;
use Deinte\Peppol\Contracts\PeppolConnector;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PeppolServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('peppol')
            ->hasConfigFile()
            ->hasMigrations([
                'create_peppol_companies_table',
                'create_peppol_invoices_table',
                'create_peppol_invoice_statuses_table',
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
