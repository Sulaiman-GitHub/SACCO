<?php

namespace Database\Factories;

use App\Models\Sacco;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sacco>
 */
class SaccoFactory extends Factory
{
    protected $model = Sacco::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Community SACCO',
            'license_number' => 'UMRA/'.fake()->year().'/'.fake()->unique()->numerify('###'),
            'tier' => fake()->randomElement(['Tier 2', 'Tier 3', 'Tier 4']),
            'phone' => '+256 7'.fake()->randomElement(['0', '7', '8']).fake()->numerify(' ### ####'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'status' => 'active',
        ];
    }
}
