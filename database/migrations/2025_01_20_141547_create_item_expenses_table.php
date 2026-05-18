<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateItemExpensesTable extends Migration
{
    public function up()
    {
        Schema::create('item_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description');
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('item_expenses');
    }
}
