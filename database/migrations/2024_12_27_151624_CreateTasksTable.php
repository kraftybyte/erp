<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('nama_task');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->text('detail_task');
            $table->dateTime('mulai_task');
            $table->dateTime('selesai_task');
            $table->string('status')->default('pending'); // Menggunakan string untuk status
            $table->string('proritas')->default('medium'); // Menggunakan string untuk prioritas
            $table->string('nama_layanan');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tasks');
    }
}
