<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sender Information
    |--------------------------------------------------------------------------
    |
    | Your company's VAT number used as the sender for PEPPOL invoices.
    | This is required for sending invoices via PEPPOL.
    |
    */

    'sender_vat_number' => env('PEPPOL_SENDER_VAT_NUMBER'),

    'currency' => env('PEPPOL_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Default PEPPOL Connector
    |--------------------------------------------------------------------------
    |
    | This option defines the default connector used to communicate with
    | the PEPPOL network. The connector handles sending invoices, looking
    | up companies, and receiving status updates.
    |
    | Supported: "scrada"
    |
    */

    'default_connector' => env('PEPPOL_CONNECTOR', 'scrada'),

    /*
    |--------------------------------------------------------------------------
    | Connector Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure the settings for each connector. Each connector
    | may have different configuration requirements.
    |
    */

    'connectors' => [

        'scrada' => [
            'api_key' => env('SCRADA_API_KEY'),
            'api_secret' => env('SCRADA_API_SECRET'),
            'company_id' => env('SCRADA_COMPANY_ID'),
            'base_url' => env('SCRADA_BASE_URL', 'https://api.scrada.be'),
            'timeout' => env('SCRADA_TIMEOUT', 30),
            'journal' => env('SCRADA_JOURNAL', 'SALES'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice Dispatch Settings
    |--------------------------------------------------------------------------
    |
    | Configure when and how invoices are dispatched to the PEPPOL network.
    |
    */

    'dispatch' => [
        // Number of days to wait before sending invoice after creation
        'delay_days' => env('PEPPOL_DISPATCH_DELAY', 7),

        // Maximum number of dispatch attempts before marking as permanently failed
        'max_attempts' => env('PEPPOL_MAX_ATTEMPTS', 3),

        // Progressive backoff delays in minutes between retry attempts
        'retry_delays' => [5, 15, 60],

        // Queue name for dispatch jobs
        'queue' => env('PEPPOL_DISPATCH_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Company Lookup Settings
    |--------------------------------------------------------------------------
    |
    | Configure how company lookups are cached and refreshed.
    |
    */

    'lookup' => [
        // Hours to cache PEPPOL ID lookups
        'cache_hours' => env('PEPPOL_LOOKUP_CACHE_HOURS', 168), // 7 days

        // Automatically lookup PEPPOL ID when company is created
        'auto_lookup' => env('PEPPOL_AUTO_LOOKUP', true),

        // Queue name for lookup jobs
        'queue' => env('PEPPOL_LOOKUP_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Poll Retry Settings
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for invoices. Invoices will be polled with
    | progressive backoff to check delivery status.
    |
    */

    'poll' => [
        // Maximum number of poll retry attempts
        'max_attempts' => env('PEPPOL_POLL_MAX_ATTEMPTS', 50),

        // Minutes to wait between retry attempts (progressive backoff)
        // 1min, 5min, 10min, 30min, 1hr, 6hr, 24hr, 7 days
        'retry_delays_minutes' => [1, 5, 10, 30, 60, 360, 1440, 10080],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Names
    |--------------------------------------------------------------------------
    |
    | Customize the table names used by the package if needed.
    |
    */

    'tables' => [
        'companies' => 'peppol_companies',
        'invoices' => 'peppol_invoices',
        'invoice_logs' => 'peppol_invoice_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Class Names
    |--------------------------------------------------------------------------
    |
    | You may override the default models used by the package.
    |
    */

    'models' => [
        'company' => Deinte\Peppol\Models\PeppolCompany::class,
        'invoice' => Deinte\Peppol\Models\PeppolInvoice::class,
        'invoice_log' => Deinte\Peppol\Models\PeppolInvoiceLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific event broadcasting.
    |
    */

    'events' => [
        'company_found' => true,
        'invoice_dispatched' => true,
        'invoice_status_changed' => true,
        'invoice_delivered' => true,
        'invoice_failed' => true,
    ],

];
