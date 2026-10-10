<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\Saving;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Saving>
 */
class SavingFactory extends Factory
{
    protected $model = Saving::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentMethod = fake()->randomElement(['cash', 'mtn_momo', 'airtel_money', 'bank_transfer']);
        $amounts = [20000, 50000, 75000, 100000, 150000, 200000, 300000, 500000];

        $paymentRef = match ($paymentMethod) {
            'mtn_momo' => 'MM'.fake()->numerify('##########'),
            'airtel_money' => 'AM'.fake()->numerify('##########'),
            'bank_transfer' => 'STAN'.fake()->numerify('########'),
            default => null,
        };

        return [
            'sacco_id' => Sacco::factory(),
            'member_id' => Member::factory(),
            'transaction_reference' => 'SAV-'.date('Y').'-'.fake()->unique()->numberBetween(10000, 99999),
            'type' => 'deposit',
            'amount' => fake()->randomElement($amounts),
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentRef,
            'transaction_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'recorded_by' => null,
            'notes' => fake()->optional(0.3)->randomElement([
                'Regular monthly member contribution',
                'Weekly market savings deposit',
                'Voluntary savings deposit',
                'Mobile money cash-in deposit',
            ]),
        ];
    }

    /**
     * Indicate that the transaction is a withdrawal.
     */
    public function withdrawal(?float $amount = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'withdrawal',
            'amount' => $amount ?? fake()->randomElement([30000, 50000, 100000, 150000]),
            'notes' => 'Member savings withdrawal',
        ]);
    }

    /**
     * Indicate that the transaction is a deposit.
     */
    public function deposit(?float $amount = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'deposit',
            'amount' => $amount ?? fake()->randomElement([50000, 100000, 200000]),
        ]);
    }
}
