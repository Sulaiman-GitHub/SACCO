<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Model>
     */
    protected $model = Member::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ugandanFirstNamesMale = ['Brian', 'Ronald', 'Dennis', 'Julius', 'Kenneth', 'Moses', 'Ivan', 'Paul', 'David', 'Simon', 'Joseph', 'Peter'];
        $ugandanFirstNamesFemale = ['Sarah', 'Grace', 'Agnes', 'Faith', 'Patricia', 'Joan', 'Christine', 'Brenda', 'Harriet', 'Mercy', 'Esther', 'Rose'];
        $ugandanSurnames = ['Okello', 'Namubiru', 'Kato', 'Mukasa', 'Akello', 'Musoke', 'Tumuhimbise', 'Nakitto', 'Byaruhanga', 'Nalubega', 'Otim', 'Kiconco', 'Mugisha', 'Nabatanzi', 'Ssebaggala', 'Atuhaire', 'Kiyimba', 'Namatovu'];

        $gender = fake()->randomElement(['male', 'female']);
        $firstName = $gender === 'male'
            ? fake()->randomElement($ugandanFirstNamesMale)
            : fake()->randomElement($ugandanFirstNamesFemale);
        $lastName = fake()->randomElement($ugandanSurnames);
        $fullName = "{$firstName} {$lastName}";

        $ninPrefix = $gender === 'male' ? 'CM' : 'CF';
        $ninNumber = $ninPrefix.fake()->regexify('[0-9]{2}[0-9A-Z]{6}[0-9]{3}[A-Z]');

        $occupations = [
            'Retail Shopkeeper',
            'Commercial Produce Farmer',
            'Boda Boda Transport Operator',
            'Primary School Teacher',
            'Metal Fabricator & Welder',
            'Fresh Produce Market Vendor',
            'Fashion & Garment Tailor',
            'Registered Clinical Nurse',
            'Automobile Mechanic',
            'Wood Carpenter & Furniture Maker',
            'Hardware Store Attendant',
            'Poultry & Dairy Farmer',
        ];

        $districts = [
            'Kawempe Division, Kampala',
            'Nakawa Market Area, Kampala',
            'Jinja Central, Jinja District',
            'Kireka Trading Centre, Wakiso',
            'Entebbe Municipality, Wakiso',
            'Pece Division, Gulu City',
            'Kamukuzi, Mbarara City',
            'Nyendo, Masaka City',
            'Central Ward, Mukono',
            'Mbale Industrial Area, Mbale',
        ];

        return [
            'sacco_id' => Sacco::factory(),
            'member_number' => 'MBR-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => $fullName,
            'national_id_hash' => Member::hashNationalId($ninNumber),
            'national_id_masked' => Member::maskNationalId($ninNumber),
            'phone' => '+256 7'.fake()->randomElement(['0', '7', '8', '5']).fake()->numerify(' ### ####'),
            'email' => fake()->optional(0.6)->safeEmail(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'gender' => $gender,
            'occupation' => fake()->randomElement($occupations),
            'address' => fake()->randomElement($districts),
            'monthly_income' => fake()->randomElement([450000, 750000, 1200000, 1800000, 2500000, 3500000, 5000000]),
            'next_of_kin_name' => fake()->randomElement($ugandanFirstNamesMale).' '.fake()->randomElement($ugandanSurnames),
            'next_of_kin_phone' => '+256 7'.fake()->randomElement(['0', '7', '8', '5']).fake()->numerify(' ### ####'),
            'next_of_kin_relationship' => fake()->randomElement(['Spouse', 'Brother', 'Sister', 'Parent', 'Child', 'Cousin']),
            'join_date' => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'status' => 'active',
            'created_by' => null,
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }

    /**
     * Give the member a full consent record (data processing and credit scoring agreed), written
     * through Member::recordConsent() exactly as a real registration does.
     */
    public function consented(User $capturedBy, bool $scoringConsent = true, bool $nextOfKinInformed = true): static
    {
        return $this->afterCreating(function (Member $member) use ($capturedBy, $scoringConsent, $nextOfKinInformed) {
            $member->recordConsent($capturedBy, $scoringConsent, $nextOfKinInformed);
        });
    }

    /**
     * Indicate that the member is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the member is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the member is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}
