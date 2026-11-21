<?php

namespace App\Console\Commands;

use App\Services\WithdrawalRequestService;
use Illuminate\Console\Command;

class ExpireWithdrawalRequests extends Command
{
    /**
     * @var string
     */
    protected $signature = 'withdrawals:expire';

    /**
     * @var string
     */
    protected $description = 'Expire savings withdrawal requests that were not paid within the days each SACCO allows';

    public function handle(WithdrawalRequestService $requests): int
    {
        $count = $requests->expireStale();

        $this->info("Expired {$count} withdrawal request(s).");

        return self::SUCCESS;
    }
}
