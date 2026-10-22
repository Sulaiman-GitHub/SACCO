<?php

namespace App\Console\Commands;

use App\Models\Loan;
use Illuminate\Console\Command;

class CheckOverdueLoans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loans:check-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan active loans across all SACCO tenants and update their overdue/default status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning loans for overdue and default transitions...');

        $activeLoans = Loan::withoutTenantScope()
            ->whereIn('status', ['disbursed', 'overdue', 'defaulted'])
            ->get();

        $updatedCount = 0;
        $overdueCount = 0;
        $defaultedCount = 0;
        $closedCount = 0;

        foreach ($activeLoans as $loan) {
            $originalStatus = $loan->status;
            $newStatus = $loan->refreshStatus();

            if ($originalStatus !== $newStatus) {
                $updatedCount++;
                $this->line("Loan {$loan->loan_reference} (ID: {$loan->id}): {$originalStatus} -> {$newStatus}");
            }

            match ($newStatus) {
                'overdue' => $overdueCount++,
                'defaulted' => $defaultedCount++,
                'closed' => $closedCount++,
                default => null,
            };
        }

        $this->info("Scan completed. Processed: {$activeLoans->count()} active loans. Status changed: {$updatedCount}.");
        $this->info("Current states: Overdue: {$overdueCount}, Defaulted: {$defaultedCount}, Auto-Closed: {$closedCount}.");

        return Command::SUCCESS;
    }
}
