<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\ItemExpense;
use App\Models\Advance;
use App\Models\ItemAdvance;
use App\Models\User;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ExpenseAndAdvanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating sample data for Analytics Dashboard...');

        // Get existing users or create some
        $users = User::limit(5)->get();
        if ($users->isEmpty()) {
            $this->command->warn('No users found, creating sample users...');
            $users = User::factory()->count(5)->create();
        }

        // Get existing projects or create some
        $projects = Project::limit(10)->get();
        if ($projects->isEmpty()) {
            $this->command->warn('No projects found, creating sample projects...');
            $projects = Project::factory()->count(10)->create();
        }

        // ========== EXPENSES ==========
        $this->command->info('Creating 50 Expenses with items...');

        for ($i = 0; $i < 50; $i++) {
            $user = $users->random();
            $project = rand(0, 10) > 3 ? $projects->random() : null;
            $status = fake()->randomElement(['Pending', 'Disetujui', 'Selesai', 'Selesai', 'Selesai']); // More completed
            $amount = fake()->randomFloat(2, 100000, 5000000);

            $expense = Expense::create([
                'user_id' => $user->id,
                'submission_date' => fake()->dateTimeBetween('-6 months', 'now'),
                'project_id' => $project?->id,
                'keterangan_expense' => fake()->sentence(4),
                'amount' => $amount,
                'reference' => 'EXP-' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'status' => $status,
                'paid' => in_array($status, ['Disetujui', 'Selesai']) ? $amount : 0,
            ]);

            // Create 1-5 items per expense
            $itemCount = rand(1, 5);
            for ($j = 0; $j < $itemCount; $j++) {
                $qty = rand(1, 10);
                $price = fake()->randomFloat(2, 25000, 500000);
                ItemExpense::create([
                    'expense_id' => $expense->id,
                    'name' => fake()->randomElement([
                        'Server Hosting', 'Cloud Storage', 'Domain SSL', 'Email Service',
                        'Google Workspace', 'Microsoft 365', 'Slack Premium', 'Zoom Pro',
                        'Figma Team', 'Adobe CC', 'AWS Services', 'VPS Server',
                        'Internet Bulanan', 'ATK Kantor', 'Kopi & Snack',
                        'Transportasi', 'Biaya Admin Bank', 'API Services',
                        'Project Management Tool', 'CRM Subscription', 'SMS Gateway',
                    ]),
                    'description' => fake()->sentence(3),
                    'quantity' => $qty,
                    'price' => $price,
                ]);
            }
        }

        // ========== ADVANCES ==========
        $this->command->info('Creating 30 Advances with items...');

        for ($i = 0; $i < 30; $i++) {
            $user = $users->random();
            $project = rand(0, 10) > 2 ? $projects->random() : null;
            $status = fake()->randomElement([
                'Pending', 'Disetujui', 'Dibayarkan', 'Selesai', 'Selesai', 'Selesai'
            ]);
            $amount = fake()->randomFloat(2, 500000, 15000000);

            $advance = Advance::create([
                'user_id' => $user->id,
                'submission_date' => fake()->dateTimeBetween('-6 months', 'now'),
                'project_id' => $project?->id,
                'keterangan_advance' => fake()->sentence(4),
                'amount' => $amount,
                'reference_number' => 'ADV-' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'status' => $status,
                'paid' => in_array($status, ['Disetujui', 'Dibayarkan', 'Selesai']) ? $amount : 0,
            ]);

            // Create 1-3 items per advance
            $itemCount = rand(1, 3);
            for ($j = 0; $j < $itemCount; $j++) {
                $qty = rand(1, 5);
                $price = fake()->randomFloat(2, 50000, 3000000);
                ItemAdvance::create([
                    'advance_id' => $advance->id,
                    'name' => fake()->randomElement([
                        'Transportasi Darat', 'Tiket Pesawat', 'Hotel & Akomodasi',
                        'Makan Siang', 'Kopi Meeting', 'Bahan Prakarsa',
                        'Equipment Rental', 'Tools & Software', 'Fotocopy & Print',
                        'Taksi & Grab', 'Bensin Kendaraan', 'Parkir', 'Tol',
                        'Catering Meeting', 'Venue Sewa', 'Dekorasi Event',
                        'Sound System', 'Crew Honor', 'Biaya Platform',
                        'Server Hosting', 'Maintenance Website',
                    ]),
                    'description' => fake()->sentence(3),
                    'quantity' => $qty,
                    'price' => $price,
                ]);
            }
        }

        $this->command->info('Sample data created successfully!');
        $this->command->info('- Expenses: ' . Expense::count());
        $this->command->info('- Expense Items: ' . ItemExpense::count());
        $this->command->info('- Advances: ' . Advance::count());
        $this->command->info('- Advance Items: ' . ItemAdvance::count());
    }
}
