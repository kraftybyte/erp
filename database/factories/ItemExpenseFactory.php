<?php

namespace Database\Factories;

use App\Models\ItemExpense;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemExpenseFactory extends Factory
{
    protected $model = ItemExpense::class;

    public function definition(): array
    {
        return [
            'expense_id' => Expense::factory(),
            'name' => fake()->randomElement([
                'Server Hosting', 'Cloud Storage', 'Domain SSL', 'Email Service',
                'Google Workspace', 'Microsoft 365', 'Slack Premium', 'Zoom Pro',
                'Figma Team', 'Adobe CC', 'AWS Services', 'VPS Server',
                'Internet Bulanan', 'Listrik Kantor', 'ATK Kantor', 'Kopi & Snack',
                'Transportasi', 'Makan Siang Meeting', 'Pengiriman Paket', 'Biaya Admin Bank',
                'Spotify Premium', 'Netflix Business', 'Notion Team', 'GitHub Enterprise',
                'Project Management Tool', 'CRM Subscription', 'API Services', 'SMS Gateway',
                'WhatsApp Business', 'Logo Design', 'Brand Kit', 'Stock Photos',
                'Font License', 'Printer Ink', 'Laptop Repair', 'Mouse & Keyboard',
                'Monitor Stand', 'USB Hub', 'Webcam', 'Headset',
            ]),
            'description' => fake()->optional()->sentence(),
            'quantity' => fake()->numberBetween(1, 10),
            'price' => fake()->randomFloat(2, 50000, 500000),
        ];
    }
}
