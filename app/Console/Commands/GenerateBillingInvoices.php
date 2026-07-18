<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class GenerateBillingInvoices extends Command
{
    protected $signature = 'billing:generate-invoices';

    protected $description = 'Generate consolidated monthly invoices for any tenant whose billing anchor date has arrived.';

    public function handle(BillingService $billingService): int
    {
        $this->info('Checking for due billing anchors...');

        $count = $billingService->generateDueRecurringInvoices();

        if ($count === 0) {
            $this->info('No invoices due today.');
        } else {
            $this->info("Generated {$count} invoice(s).");
        }

        return self::SUCCESS;
    }
}
