<?php

namespace Database\Factories;

use App\Models\ConsentChangeRequest;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentChangeRequest>
 */
class ConsentChangeRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ConsentChangeRequest>
     */
    protected $model = ConsentChangeRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'sacco_id' => fn (array $attributes) => Member::withoutTenantScope()->find($attributes['member_id'])->sacco_id,
            'request_type' => ConsentChangeRequest::TYPE_WITHDRAW_SCORING,
            'member_note' => null,
            'status' => 'pending',
        ];
    }
}
