<?php

namespace Database\Factories;

use App\Models\ItemAdvance;
use App\Models\Advance;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemAdvanceFactory extends Factory
{
    protected $model = ItemAdvance::class;

    public function definition(): array
    {
        return [
            'advance_id' => Advance::factory(),
            'name' => fake()->randomElement([
                'Transportasi Darat', 'Tiket Pesawat', 'Hotel & Akomodasi',
                'Makan Siang', 'Makan Malam Klien', 'Kopi Meeting',
                'Bahan Prakarsa', 'Equipment Rental', 'Tools & Software',
                'Fotocopy & Print', 'Kurir & Pengiriman', 'Taksi & Grab',
                'Bensin Kendaraan', 'Parkir', 'Tol',
                'Catering Meeting', 'Venue Sewa', ' Dekorasi Event',
                'Sound System', 'Lighting Rental', 'Crew Honor',
                'Host & MC', 'Security', 'Cleaning Service',
                'Biaya Platform', 'Server Hosting', 'Domain Renewal',
                'SSL Certificate', 'Maintenance Website', 'Update Software',
                'Training & Workshop', 'Sertifikasi', 'Conference Fee',
                'Office Supplies', 'Peralatan Kantor', 'Furniture Minor',
            ]),
            'description' => fake()->optional()->sentence(),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => fake()->randomFloat(2, 25000, 1500000),
        ];
    }
}
