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
        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->onDelete('cascade');
            $table->string('item')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('due_date')->nullable();
            $table->string('notes')->nullable();
            $table->enum('type', ['fee', 'third_party', 'sow'])->default('fee');
            $table->integer('quantity')->nullable()->default(1);
            $table->string('unit')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_items');
    }
};
