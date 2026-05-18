<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik')->unique()->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('position')->nullable();
            $table->string('npwp')->nullable()->unique();
            $table->string('division')->nullable();
        });
    }

    public function down(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['nik', 'phone', 'address', 'position', 'npwp', 'division']);
        });
    }
};
