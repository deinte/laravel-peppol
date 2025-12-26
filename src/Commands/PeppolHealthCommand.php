<?php

declare(strict_types=1);

namespace Deinte\Peppol\Commands;

use Deinte\Peppol\Contracts\PeppolConnector;
use Illuminate\Console\Command;

class PeppolHealthCommand extends Command
{
    protected $signature = 'peppol:health';

    protected $description = 'Check if the PEPPOL connector API is configured correctly and reachable';

    public function handle(PeppolConnector $connector): int
    {
        $this->info('Checking PEPPOL connector health...');
        $this->newLine();

        $connectorClass = get_class($connector);
        $this->line("Connector: <comment>{$connectorClass}</comment>");

        try {
            $result = $connector->healthCheck();

            if ($result['healthy']) {
                $this->info('✓ API connection successful');

                if (isset($result['company_count'])) {
                    $this->line("  Companies found: <comment>{$result['company_count']}</comment>");
                }

                if (isset($result['message'])) {
                    $this->line("  {$result['message']}");
                }

                $this->newLine();
                $this->info('PEPPOL connector is configured correctly.');

                return self::SUCCESS;
            }

            $this->error('✗ API connection failed');

            if (isset($result['error'])) {
                $this->line("  Error: <error>{$result['error']}</error>");
            }

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('✗ API connection failed');
            $this->line("  Error: <error>{$e->getMessage()}</error>");
            $this->newLine();
            $this->warn('Please check your PEPPOL configuration in config/peppol.php');

            return self::FAILURE;
        }
    }
}
