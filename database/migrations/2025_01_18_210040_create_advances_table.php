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
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_id');
            $table->date('submission_date');
            $table->unsignedBigInteger('project_id');
            $table->text('keterangan_expense');
            $table->string('status');
            $table->decimal('amount', 15, 2);
            $table->string('attachment')->nullable();
            $table->boolean('paid')->default(false);
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('refrence')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('attachment_pay')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advances');
    }
};
