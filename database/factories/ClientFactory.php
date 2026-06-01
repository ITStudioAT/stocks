<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'signature' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'headline' => fake()->sentence(5),
            'subheadline' => fake()->sentence(8),
            'body' => fake()->paragraph(3),
            'is_published' => true,
            'is_active' => false,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => [
            'is_published' => false,
        ]);
    }
}
