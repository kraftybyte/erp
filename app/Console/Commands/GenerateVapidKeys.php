<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:generate-vapid-keys';

    protected $description = 'Generate VAPID keys for web push notifications';

    public function handle(): int
    {
        $this->info('Generating VAPID keys...');

        // Generate a random key pair (simplified version)
        // For production, use web-push PHP library
        $privateKey = Str::random(64);
        $publicKey = Str::random(44);

        $this->info('VAPID Keys Generated!');
        $this->newLine();
        $this->line('Add these to your .env file:');
        $this->newLine();

        $this->info('VAPID_PUBLIC_KEY=' . $publicKey);
        $this->info('VAPID_PRIVATE_KEY=' . $privateKey);
        $this->info('VAPID_SUBJECT=mailto:admin@yourdomain.com');

        $this->newLine();
        $this->warn('For production, install web-push package:');
        $this->line('composer require minishlink/web-push');

        return Command::SUCCESS;
    }
}