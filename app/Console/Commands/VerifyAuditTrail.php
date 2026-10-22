<?php

namespace App\Console\Commands;

use App\Models\Sacco;
use App\Services\AuditTrail;
use Illuminate\Console\Command;

class VerifyAuditTrail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:verify {sacco_id? : Limit verification to a single SACCO}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the audit log hash chain and that recorded ledger entries still match the ledger';

    /**
     * Execute the console command.
     */
    public function handle(AuditTrail $auditTrail): int
    {
        $saccos = $this->argument('sacco_id')
            ? Sacco::whereKey($this->argument('sacco_id'))->get()
            : Sacco::all();

        if ($saccos->isEmpty()) {
            $this->error('No matching SACCO found.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($saccos as $sacco) {
            $result = $auditTrail->verify($sacco->id);

            if ($result['ok']) {
                $this->info("{$sacco->name}: OK ({$result['checked']} entries verified)");

                continue;
            }

            $failed = true;
            $this->error("{$sacco->name}: INTEGRITY PROBLEMS");

            foreach ($result['problems'] as $problem) {
                $this->line("  - {$problem}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
