<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Unique key for code reference (e.g., log, process)');
            $table->string('name')->comment('Display name in UI');
            $table->string('color')->default('#94a3b8')->comment('Hex color code');
            $table->string('background_color')->default('#f8fafc')->comment('Background color for column');
            $table->integer('sort_order')->default(0)->comment('Order in kanban board');
            $table->boolean('is_active')->default(true)->comment('Whether this stage is active');
            $table->timestamps();

            $table->index('sort_order');
            $table->index('is_active');
        });

        // Add stage column to tasks table if it doesn't exist
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'stage_key')) {
                $table->string('stage_key')->nullable()->after('status')->comment('FK to task_stages.key');
                $table->index('stage_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'stage_key')) {
                $table->dropIndex(['stage_key']);
                $table->dropColumn('stage_key');
            }
        });

        Schema::dropIfExists('task_stages');
    }
};
