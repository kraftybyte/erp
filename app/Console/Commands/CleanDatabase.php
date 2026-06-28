<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDatabase extends Command
{
    protected $signature = 'db:clean';

    protected $description = 'Delete all data except users, roles, permissions, and settings';

    public function handle(): int
    {
        $this->info('Cleaning database...');

        // Tables to keep (structure + data)
        $keepTables = [
            'migrations',
            'users',
            'roles',
            'permissions',
            'role_has_permissions',
            'model_has_roles',
            'model_has_permissions',
            'settings',
            'password_reset_tokens',
            'sessions',
            'personal_access_tokens',
            'failed_jobs',
            'jobs',
            'notifications',
            'filament_exceptions_table',
            'languages',
            'currencies',
            'countries',
            'areas',
            'cities',
            'locations',
            'types',
            'types_metas',
            'folders',
            'folder_has_models',
            'media',
            'media_has_models',
        ];

        // Get all tables
        $tables = DB::select('SHOW TABLES');
        $dbName = array_keys((array) $tables[0])[0];

        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($tables as $table) {
            $tableName = $table->$dbName;

            if (in_array($tableName, $keepTables)) {
                $this->line("Skipping: {$tableName}");
                $skippedCount++;
                continue;
            }

            try {
                DB::table($tableName)->delete();
                $this->info("Deleted: {$tableName}");
                $deletedCount++;
            } catch (\Exception $e) {
                $this->error("Error deleting {$tableName}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Done! Deleted {$deletedCount} tables, skipped {$skippedCount} tables.");

        return Command::SUCCESS;
    }
}
