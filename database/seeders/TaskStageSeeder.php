<?php

namespace Database\Seeders;

use App\Models\TaskStage;
use Illuminate\Database\Seeder;

class TaskStageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $stages = [
            [
                'key' => 'log',
                'name' => 'Log',
                'color' => '#f59e0b',
                'background_color' => '#fffbeb',
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'key' => 'process',
                'name' => 'Process',
                'color' => '#0891b2',
                'background_color' => '#ecfeff',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'key' => 'review',
                'name' => 'Review',
                'color' => '#9333ea',
                'background_color' => '#faf5ff',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'key' => 'done',
                'name' => 'Done',
                'color' => '#16a34a',
                'background_color' => '#f0fdf4',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'key' => 'hold',
                'name' => 'Hold',
                'color' => '#ef4444',
                'background_color' => '#fef2f2',
                'sort_order' => 4,
                'is_active' => true,
            ],
        ];

        foreach ($stages as $stage) {
            TaskStage::updateOrCreate(
                ['key' => $stage['key']],
                $stage
            );
        }

        // Clear cache
        TaskStage::clearCache();
    }
}
