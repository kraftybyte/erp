<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('client_signatory_name')->nullable()->after('client_email');
            $table->string('client_signatory_title')->nullable()->after('client_signatory_name');
            $table->string('agency_signatory_name')->nullable()->after('client_signatory_title');
            $table->string('agency_signatory_title')->nullable()->after('agency_signatory_name');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['client_signatory_name', 'client_signatory_title', 'agency_signatory_name', 'agency_signatory_title']);
        });
    }
};