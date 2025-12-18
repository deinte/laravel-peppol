<?php

declare(strict_types=1);

namespace Deinte\Peppol\Tests;

use Deinte\Peppol\PeppolServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Deinte\\Peppol\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            PeppolServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up peppol config
        config()->set('peppol', [
            'default_connector' => 'scrada',
            'connectors' => [
                'scrada' => [
                    'api_key' => 'test-api-key',
                    'api_secret' => 'test-api-secret',
                    'company_id' => 'test-company-id',
                    'base_url' => null,
                ],
            ],
            'lookup' => [
                'cache_hours' => 168,
            ],
            'dispatch' => [
                'delay_days' => 7,
                'queue' => 'default',
                'max_attempts' => 3,
                'retry_delays' => [5, 15, 60],
            ],
            'poll' => [
                'max_attempts' => 50,
                'retry_delays' => [1, 4, 12, 24, 48],
            ],
            'events' => [
                'invoice_dispatched' => true,
                'invoice_failed' => true,
            ],
        ]);

        // Set up logging
        config()->set('logging.channels.peppol', [
            'driver' => 'single',
            'path' => storage_path('logs/peppol.log'),
            'level' => 'debug',
        ]);

        // Run migrations from stub files in correct order
        $this->runMigrations();
    }

    protected function runMigrations(): void
    {
        $migrationsPath = __DIR__.'/../database/migrations';

        // Define migration order - base tables first, then modifications
        $migrationOrder = [
            'create_peppol_companies_table.php.stub',
            'create_peppol_invoices_table.php.stub',
            'create_peppol_invoice_statuses_table.php.stub',
            'add_skip_peppol_delivery_to_peppol_invoices_table.php.stub',
            'add_tax_number_fields_to_peppol_companies_table.php.stub',
            'add_connector_tracking_to_peppol_invoices_table.php.stub',
            'add_poll_retry_fields_to_peppol_invoices_table.php.stub',
            'add_polling_indexes_to_peppol_invoices_table.php.stub',
            'add_payload_columns_to_peppol_invoices_table.php.stub',
            'simplify_peppol_schema.php.stub',
        ];

        foreach ($migrationOrder as $stubFile) {
            $fullPath = "{$migrationsPath}/{$stubFile}";

            if (file_exists($fullPath)) {
                $migration = require $fullPath;
                $migration->up();
            }
        }
    }
}
