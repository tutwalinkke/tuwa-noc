<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class ProcessOverdueBilling extends Command
{
    protected $signature = 'billing:process-overdue';

    protected $description = 'Mark unpaid invoices past their due date as overdue, and block tenants with overdue balances.';

    public function handle(BillingService $billingService): int
    {
        $this->info('Checking for overdue invoices...');

        $blockedCount = $billingService->processOverdueAccounts();

        if ($blockedCount === 0) {
            $this->info('No accounts newly blocked.');
        } else {
            $this->warn("Blocked {$blockedCount} account(s) for non-payment.");
        }

        return self::SUCCESS;
    }
}
