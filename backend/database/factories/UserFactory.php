<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'user_type' => 'customer',
            'full_name' => fake()->name(),
            'phone_e164' => '+9689'.fake()->unique()->numerify('#######'),
            'preferred_locale' => 'ar',
            'status' => 'active',
        ];
    }

    public function staff(): static
    {
        return $this->state(fn () => [
            'user_type' => 'staff',
            'phone_e164' => null,
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => 'password',
        ]);
    }
}
