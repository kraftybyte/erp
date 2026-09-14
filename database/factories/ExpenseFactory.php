<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\User;
use App\Models\Project;
use App\Models\PaymentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['Pending', 'Disetujui', 'Selesai', 'Ditolak']);
        $amount = fake()->randomFloat(2, 50000, 5000000);

        return [
            'user_id' => User::factory(),
            'submission_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'project_id' => fake()->boolean(70) ? Project::factory() : null,
            'keterangan_expense' => fake()->sentence(6),
            'amount' => $amount,
            'attachment' => null,
            'reference' => 'EXP-' . fake()->unique()->numerify('######'),
            'status' => $status,
            'rejection_reason' => null,
            'attachment_pay' => null,
            'payment_account_id' => $status === 'Selesai' ? PaymentAccount::factory() : null,
            'paid' => in_array($status, ['Disetujui', 'Selesai']) ? $amount : 0,
            'paid_by' => $status === 'Selesai' ? User::factory() : null,
            'approved_by' => in_array($status, ['Disetujui', 'Selesai']) ? User::factory() : null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Pending',
            'paid' => 0,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Disetujui',
            'paid' => $attributes['amount'] ?? 0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Selesai',
            'paid' => $attributes['amount'] ?? 0,
        ]);
    }
}
