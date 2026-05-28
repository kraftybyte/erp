<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate existing quotations to new format: #XX/013YYMM/XXXXX
        $quotations = DB::table('quotations')->get();

        foreach ($quotations as $quot) {
            $oldUuid = $quot->uuid;

            // Extract prefix (e.g., DG from #DG/0226/00001)
            if (preg_match('/^#([A-Z]+)\/(\d{4})\/(\d{5})$/', $oldUuid, $matches)) {
                $prefix = $matches[1];
                $dateYYMM = $matches[2]; // YYMM format
                $sequence = $matches[3];

                $newUuid = '#' . $prefix . '/013' . $dateYYMM . '/' . $sequence;

                DB::table('quotations')
                    ->where('id', $quot->id)
                    ->update(['uuid' => $newUuid]);
            }
        }

        // Migrate existing invoices to new format: #XX/013YYMM/XXXXX
        $invoices = DB::table('invoices')->get();

        foreach ($invoices as $inv) {
            $oldUuid = $inv->uuid;

            // Match patterns like #PI/0130226/00001 or #DG/0130126/00001 or #PI/YYMM/XXXXX
            if (preg_match('/^#([A-Z]+)\/(\d{6})\/(\d{5})$/', $oldUuid, $matches)) {
                // Already has /013 format, just ensure correct format
                $prefix = $matches[1];
                $datePart = $matches[2]; // Already has 013 prefix
                $sequence = $matches[3];

                $newUuid = '#' . $prefix . '/' . $datePart . '/' . $sequence;

                DB::table('invoices')
                    ->where('id', $inv->id)
                    ->update(['uuid' => $newUuid]);
            } elseif (preg_match('/^#([A-Z]+)\/(\d{4})\/(\d{5})$/', $oldUuid, $matches)) {
                // Convert from old format like #PI/0226/00001 to #PI/0130226/00001
                $prefix = $matches[1];
                $dateYYMM = $matches[2];
                $sequence = $matches[3];

                $newUuid = '#' . $prefix . '/013' . $dateYYMM . '/' . $sequence;

                DB::table('invoices')
                    ->where('id', $inv->id)
                    ->update(['uuid' => $newUuid]);
            }
        }
    }

    public function down(): void
    {
        // Rollback quotations
        $quotations = DB::table('quotations')->get();
        foreach ($quotations as $quot) {
            $oldUuid = $quot->uuid;

            if (preg_match('/^#([A-Z]+)\/013(\d{4})\/(\d{5})$/', $oldUuid, $matches)) {
                $prefix = $matches[1];
                $dateYYMM = $matches[2];
                $sequence = $matches[3];

                $newUuid = '#' . $prefix . '/' . $dateYYMM . '/' . $sequence;

                DB::table('quotations')
                    ->where('id', $quot->id)
                    ->update(['uuid' => $newUuid]);
            }
        }

        // Rollback invoices
        $invoices = DB::table('invoices')->get();
        foreach ($invoices as $inv) {
            $oldUuid = $inv->uuid;

            if (preg_match('/^#([A-Z]+)\/013(\d{4})\/(\d{5})$/', $oldUuid, $matches)) {
                $prefix = $matches[1];
                $dateYYMM = $matches[2];
                $sequence = $matches[3];

                $newUuid = '#' . $prefix . '/' . $dateYYMM . '/' . $sequence;

                DB::table('invoices')
                    ->where('id', $inv->id)
                    ->update(['uuid' => $newUuid]);
            }
        }
    }
};
