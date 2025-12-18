<?php

declare(strict_types=1);

namespace Deinte\Peppol\Tests;

use Deinte\Peppol\PeppolServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
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
                'retry_delay_minutes' => 60,
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

        // Run migrations manually from stub files
        $this->runMigrations();
    }

    protected function runMigrations(): void
    {
        // Create peppol_companies table
        Schema::create('peppol_companies', function ($table) {
            $table->id();
            $table->string('vat_number')->unique();
            $table->string('peppol_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('tax_number_scheme', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('last_lookup_at')->nullable();
            $table->timestamps();
        });

        // Create peppol_invoices table
        Schema::create('peppol_invoices', function ($table) {
            $table->id();
            $table->morphs('invoiceable');
            $table->unsignedBigInteger('recipient_peppol_company_id')->nullable()->index();
            $table->string('connector_invoice_id')->nullable()->index();
            $table->string('connector_type')->nullable();
            $table->string('connector_status')->default('PENDING');
            $table->text('connector_error')->nullable();
            $table->timestamp('connector_uploaded_at')->nullable();
            $table->string('status')->default('PENDING')->index();
            $table->boolean('skip_peppol_delivery')->default(false);
            $table->text('status_message')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->timestamp('scheduled_dispatch_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('poll_response')->nullable();
            $table->timestamps();
            $table->index(['dispatched_at', 'scheduled_dispatch_at']);
        });

        // Create peppol_invoice_statuses table
        Schema::create('peppol_invoice_statuses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('peppol_invoice_id')->index();
            $table->string('status')->index();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['peppol_invoice_id', 'created_at']);
        });
    }
}
