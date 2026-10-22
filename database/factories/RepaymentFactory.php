<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Repayment;
use App\Models\Sacco;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repayment>
 */
class RepaymentFactory extends Factory
{
    protected $model = Repayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentMethod = fake()->randomElement(['cash', 'mtn_momo', 'airtel_money', 'bank_transfer']);
        $amounts = [50000, 100000, 150000, 200000, 250000, 500000];

        $paymentRef = match ($paymentMethod) {
            'mtn_momo' => 'MM'.fake()->numerify('##########'),
            'airtel_money' => 'AM'.fake()->numerify('##########'),
            'bank_transfer' => 'BNK'.fake()->numerify('########'),
            default => null,
        };

        return [
            'sacco_id' => Sacco::factory(),
            'loan_id' => Loan::factory(),
            'member_id' => fn (array $attributes) => Loan::find($attributes['loan_id'])?->member_id ?? Member::factory(),
            'repayment_reference' => 'REP-'.date('Y').'-'.fake()->unique()->numberBetween(10000, 99999),
            'amount' => fake()->randomElement($amounts),
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentRef,
            'repayment_date' => Carbon::now()->subDays(fake()->numberBetween(0, 30))->format('Y-m-d'),
            'recorded_by' => null,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }
}
