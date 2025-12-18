<?php

/**
 * PHPStan bootstrap file.
 *
 * Provides dummy configuration for static analysis.
 */

// Set dummy Scrada config for PHPStan analysis
config([
    'peppol.default_connector' => 'scrada',
    'peppol.connectors.scrada.api_key' => 'phpstan-dummy-key',
    'peppol.connectors.scrada.api_secret' => 'phpstan-dummy-secret',
    'peppol.connectors.scrada.company_id' => 'phpstan-dummy-company',
    'peppol.connectors.scrada.base_url' => 'https://api.scrada.be',
]);
