<?php

namespace Database\Factories;

use App\Models\Advance;
use App\Models\User;
use App\Models\Project;
use App\Models\PaymentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdvanceFactory extends Factory
{
    protected $model = Advance::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['Pending', 'Disetujui', 'Dibayarkan', 'Selesai', 'Ditolak', 'Sedang di-Realisasi', 'Sedang di-Realisasi User']);
        $amount = fake()->randomFloat(2, 100000, 10000000);

        return [
            'user_id' => User::factory(),
            'submission_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'project_id' => fake()->boolean(70) ? Project::factory() : null,
            'keterangan_advance' => fake()->sentence(6),
            'amount' => $amount,
            'attachment' => null,
            'reference_number' => 'ADV-' . fake()->unique()->numerify('######'),
            'status' => $status,
            'rejection_reason' => null,
            'attachment_pay' => null,
            'total_harga' => $amount,
            'payment_account_id' => in_array($status, ['Dibayarkan', 'Selesai']) ? PaymentAccount::factory() : null,
            'paid' => in_array($status, ['Disetujui', 'Dibayarkan', 'Selesai']) ? $amount : 0,
            'paid_by' => in_array($status, ['Dibayarkan', 'Selesai']) ? User::factory() : null,
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

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'Dibayarkan',
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
