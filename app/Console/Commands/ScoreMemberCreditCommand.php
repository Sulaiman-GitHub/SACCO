<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\CreditRiskScoringService;
use Illuminate\Console\Command;

class ScoreMemberCreditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credit:score-member {member_id : The ID of the member to evaluate} {principal=1000000 : The proposed loan principal amount in UGX}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract credit features and compute ML credit risk score for a SACCO member';

    /**
     * Execute the console command.
     */
    public function handle(CreditRiskScoringService $scoringService): int
    {
        $memberId = (int) $this->argument('member_id');
        $principal = (float) $this->argument('principal');

        $member = Member::withoutTenantScope()->find($memberId);

        if (! $member) {
            $this->error("Member with ID {$memberId} not found.");

            return Command::FAILURE;
        }

        if (! $member->canBeScored()) {
            $this->error($member->hasDeclinedScoring()
                ? "{$member->full_name} declined credit scoring. No score was calculated."
                : "{$member->full_name} has no consent record. No score was calculated.");

            return Command::FAILURE;
        }

        $this->info('===============================================================');
        $this->info('  SACCO ML CREDIT RISK ASSESSMENT ENGINE');
        $this->info('===============================================================');
        $this->line("Member:     {$member->full_name} ({$member->member_number})");
        $this->line("SACCO ID:   {$member->sacco_id}");
        $this->line('Principal:  UGX '.number_format($principal, 2));
        $this->newLine();

        $result = $scoringService->scoreMember($member, $principal);

        $this->info('--- EXTRACTED FEATURE VECTOR ---');
        $features = $result['features'];
        $featureRows = [
            ['contribution_frequency', $features['contribution_frequency'], 'Deposits / month active'],
            ['contribution_consistency', $features['contribution_consistency'], 'CV (std/mean) of deposit amounts'],
            ['savings_balance', 'UGX '.number_format($features['savings_balance'], 2), 'Current net savings'],
            ['tenure_months', $features['tenure_months'].' months', 'Time since joining SACCO'],
            ['past_loans_count', $features['past_loans_count'], 'Total completed loans'],
            ['past_default_count', $features['past_default_count'], 'Historical defaults'],
            ['on_time_repayment_rate', ($features['on_time_repayment_rate'] * 100).'%', 'On-time repayment percentage'],
            ['loan_to_savings_ratio', $features['loan_to_savings_ratio'].'x', 'Leverage ratio (loan / savings)'],
        ];
        $this->table(['Feature', 'Value', 'Description'], $featureRows);

        $this->newLine();
        $this->info('--- ML SCORING INFERENCE ---');
        $engineStatus = $result['success'] ? '<fg=green>Connected (Python Microservice)</>' : '<fg=yellow>Offline (Deterministic Fallback)</>';
        $this->line("Engine Status:      {$engineStatus}");
        if ($result['error']) {
            $this->line("Notice:             {$result['error']}");
        }
        $this->line("Algorithm:          {$result['champion_algorithm']} (v{$result['model_version']})");

        $bandColor = match ($result['risk_band']) {
            'low' => 'green',
            'medium' => 'yellow',
            default => 'red',
        };

        $this->line("Risk Score:         <fg={$bandColor};options=bold>{$result['risk_score']} / 100</> (ranking score, not a probability)");
        $this->line(($result['probability_calibrated'] ?? false)
            ? 'Est. Probability:   '.round($result['default_probability'] * 100, 1).'% (calibrated on synthetic data)'
            : 'Est. Probability:   not calibrated, use the score for ranking only');
        $this->line("Risk Category:      <fg={$bandColor};options=bold>".strtoupper($result['risk_band']).' RISK</>');

        if (! empty($result['feature_contributions'])) {
            $this->newLine();
            $this->info('--- RISK DRIVERS (score points vs an average member; + raises risk) ---');
            $driverRows = [];
            foreach ($result['feature_contributions'] as $key => $points) {
                $driverRows[] = [ucwords(str_replace('_', ' ', $key)), ($points > 0 ? '+' : '').$points];
            }
            $this->table(['Factor', 'Score points'], $driverRows);
        }

        $this->newLine();

        return Command::SUCCESS;
    }
}
