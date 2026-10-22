<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Sacco;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $principals = [500000, 1000000, 1500000, 2000000, 3000000, 5000000];
        $principal = fake()->randomElement($principals);
        $rate = fake()->randomElement([8.0, 10.0, 12.0, 15.0]);
        $term = fake()->randomElement([3, 6, 12]);
        $interest = Loan::calculateInterest($principal, $rate, $term);
        $totalAmount = $principal + $interest;

        return [
            'sacco_id' => Sacco::factory(),
            'member_id' => Member::factory(),
            'loan_reference' => 'LN-'.date('Y').'-'.fake()->unique()->numberBetween(10000, 99999),
            'principal' => $principal,
            'interest_rate' => $rate,
            'interest_calculation_type' => 'flat',
            'term_months' => $term,
            'total_interest' => $interest,
            'total_amount' => $totalAmount,
            'purpose' => fake()->randomElement([
                'Agricultural inputs & fertilizer',
                'Retail shop stock restocking',
                'School tuition fees',
                'Small business equipment',
                'Emergency medical costs',
            ]),
            'status' => 'pending',
            'applied_at' => Carbon::now()->subDays(fake()->numberBetween(1, 30))->format('Y-m-d'),
            'approved_at' => null,
            'approved_by' => null,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'disbursed_at' => null,
            'disbursed_by' => null,
            'disbursement_method' => null,
            'disbursement_reference' => null,
            'due_date' => null,
            'guarantor_name' => fake()->name(),
            'guarantor_phone' => '+256 7'.fake()->numberBetween(7, 8).fake()->numerify('######'),
            'guarantor_relationship' => fake()->randomElement(['Spouse', 'Sibling', 'Business Partner', 'Parent']),
            'risk_score' => null,
            'decision' => null,
            'decision_reason' => null,
            'created_by' => null,
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }

    /**
     * State: Approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => Carbon::now()->subDays(5),
        ]);
    }

    /**
     * State: Disbursed.
     */
    public function disbursed(?Carbon $disbursedDate = null): static
    {
        return $this->state(function (array $attributes) use ($disbursedDate) {
            $disbursed = $disbursedDate ?? Carbon::now()->subMonths(1);
            $term = $attributes['term_months'] ?? 6;
            $dueDate = (clone $disbursed)->addMonths($term);

            return [
                'status' => 'disbursed',
                'approved_at' => (clone $disbursed)->subDays(2),
                'disbursed_at' => $disbursed,
                'disbursement_method' => 'bank_transfer',
                'disbursement_reference' => 'STAN'.fake()->numerify('########'),
                'due_date' => $dueDate->format('Y-m-d'),
            ];
        });
    }

    /**
     * State: Overdue (past due date by <= 90 days).
     */
    public function overdue(int $daysPastDue = 35): static
    {
        return $this->state(function (array $attributes) use ($daysPastDue) {
            $term = $attributes['term_months'] ?? 6;
            $dueDate = Carbon::now()->subDays($daysPastDue);
            $disbursed = (clone $dueDate)->subMonths($term);

            return [
                'status' => 'overdue',
                'approved_at' => (clone $disbursed)->subDays(3),
                'disbursed_at' => $disbursed,
                'disbursement_method' => 'cash',
                'due_date' => $dueDate->format('Y-m-d'),
            ];
        });
    }

    /**
     * State: Defaulted (past due date by > 90 days).
     */
    public function defaulted(int $daysPastDue = 110): static
    {
        return $this->state(function (array $attributes) use ($daysPastDue) {
            $term = $attributes['term_months'] ?? 6;
            $dueDate = Carbon::now()->subDays($daysPastDue);
            $disbursed = (clone $dueDate)->subMonths($term);

            return [
                'status' => 'defaulted',
                'ever_defaulted' => true,
                'first_default_date' => (clone $dueDate)->addDays(Loan::DEFAULT_THRESHOLD_DAYS + 1)->format('Y-m-d'),
                'approved_at' => (clone $disbursed)->subDays(3),
                'disbursed_at' => $disbursed,
                'disbursement_method' => 'mtn_momo',
                'disbursement_reference' => 'MM'.fake()->numerify('##########'),
                'due_date' => $dueDate->format('Y-m-d'),
            ];
        });
    }

    /**
     * State: Closed.
     */
    public function closed(): static
    {
        return $this->state(function (array $attributes) {
            $term = $attributes['term_months'] ?? 6;
            $disbursed = Carbon::now()->subMonths($term + 1);
            $dueDate = (clone $disbursed)->addMonths($term);

            return [
                'status' => 'closed',
                'approved_at' => (clone $disbursed)->subDays(2),
                'disbursed_at' => $disbursed,
                'disbursement_method' => 'airtel_money',
                'disbursement_reference' => 'AM'.fake()->numerify('##########'),
                'due_date' => $dueDate->format('Y-m-d'),
            ];
        });
    }

    /**
     * State: Rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'rejected_at' => Carbon::now()->subDays(3),
            'rejection_reason' => 'Insufficient member savings balance to support requested principal amount.',
        ]);
    }
}
