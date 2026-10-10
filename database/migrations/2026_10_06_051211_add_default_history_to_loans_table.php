<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days past the due date after which a loan counts as defaulted (Loan::DEFAULT_THRESHOLD_DAYS).
     * Kept literal so this migration keeps working if the model constant ever changes.
     */
    private const DEFAULT_THRESHOLD_DAYS = 90;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('ever_defaulted')->default(false)->after('status');
            $table->date('first_default_date')->nullable()->after('ever_defaulted');

            $table->index(['member_id', 'ever_defaulted']);
        });

        $this->backfillDefaultHistory();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'ever_defaulted']);
            $table->dropColumn(['ever_defaulted', 'first_default_date']);
        });
    }

    /**
     * Flag loans that already crossed the default threshold.
     *
     * Uses the query builder on purpose: it bypasses tenant scoping and model events, so existing
     * audit hashes are untouched. A loan defaulted when it still had an unpaid balance on the first
     * day past the threshold (due date + 91 days), which also catches loans that were later repaid
     * and are now 'closed'. Loans whose status is already 'defaulted' are always flagged.
     */
    private function backfillDefaultHistory(): void
    {
        $today = Carbon::today();

        DB::table('loans')
            ->whereNotNull('due_date')
            ->whereIn('status', ['disbursed', 'overdue', 'defaulted', 'closed'])
            ->orderBy('id')
            ->chunkById(200, function ($loans) use ($today) {
                foreach ($loans as $loan) {
                    $defaultDate = Carbon::parse($loan->due_date)->addDays(self::DEFAULT_THRESHOLD_DAYS + 1);

                    if ($loan->status === 'defaulted') {
                        $firstDefaultDate = $defaultDate->isFuture() ? $today : $defaultDate;
                    } elseif ($defaultDate->lte($today) && $this->hadBalanceOn($loan, $defaultDate)) {
                        $firstDefaultDate = $defaultDate;
                    } else {
                        continue;
                    }

                    DB::table('loans')->where('id', $loan->id)->update([
                        'ever_defaulted' => true,
                        'first_default_date' => $firstDefaultDate->toDateString(),
                    ]);
                }
            });
    }

    /**
     * Whether repayments dated before the given day left part of the loan unpaid.
     */
    private function hadBalanceOn(object $loan, Carbon $day): bool
    {
        $paid = (float) DB::table('repayments')
            ->where('loan_id', $loan->id)
            ->where('repayment_date', '<', $day->toDateString())
            ->sum('amount');

        return $paid + 0.001 < (float) $loan->total_amount;
    }
};
