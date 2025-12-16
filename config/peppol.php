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

        // Maximum number of retry attempts for failed dispatches
        'max_retries' => env('PEPPOL_MAX_RETRIES', 3),

        // Minutes to wait between retry attempts
        'retry_delay_minutes' => env('PEPPOL_RETRY_DELAY', 60),

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
    | Configure retry behavior for failed invoices. Failed invoices will be
    | re-polled with exponential backoff to check if the recipient has
    | registered on PEPPOL.
    |
    */

    'poll' => [
        // Maximum number of poll retry attempts for failed invoices
        'max_attempts' => env('PEPPOL_POLL_MAX_ATTEMPTS', 5),

        // Hours to wait between retry attempts (exponential backoff)
        // Example: [1, 4, 12, 24, 48] means:
        // - 1st retry after 1 hour
        // - 2nd retry after 4 hours
        // - 3rd retry after 12 hours
        // - 4th retry after 24 hours
        // - 5th retry after 48 hours
        'retry_delays' => [1, 4, 12, 24, 48],
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
        'invoice_statuses' => 'peppol_invoice_statuses',
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
        'company' => \Deinte\Peppol\Models\PeppolCompany::class,
        'invoice' => \Deinte\Peppol\Models\PeppolInvoice::class,
        'invoice_status' => \Deinte\Peppol\Models\PeppolInvoiceStatus::class,
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
