<?php

namespace Database\Factories;

use App\Models\LendingPolicy;
use App\Models\Sacco;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LendingPolicy>
 */
class LendingPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sacco_id' => Sacco::factory(),
            'approve_below_score' => 50.00,
            'reject_at_or_above_score' => 75.00,
            'max_loan_to_savings_ratio' => 3.00,
            'min_tenure_months' => 3,
            'reject_on_past_default' => false,
        ];
    }
}
