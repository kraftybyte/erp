<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tanpa cron schedule:run, pengingat dan slip gaji otomatis tidak terkirim
 * tanpa ada yang tahu: jadwal meninggalkan tanda, dan admin diperingatkan.
 */
class SchedulerHeartbeatTest extends TestCase
{
    use DatabaseTransactions;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('framework/scheduler-heartbeat');
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    public function test_jadwal_meninggalkan_tanda_setiap_menit(): void
    {
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(fn ($event) => $event->description === 'heartbeat' && $event->expression === '* * * * *'));
        $this->assertSame('Belum pernah berjalan di server ini.', SchedulerHeartbeat::status());

        SchedulerHeartbeat::beat();

        $this->assertTrue(SchedulerHeartbeat::isRunning());
        $this->assertStringStartsWith('Berjalan', SchedulerHeartbeat::status());
    }

    public function test_admin_diperingatkan_bila_jadwal_tidak_berjalan(): void
    {
        $this->app['env'] = 'production';
        $admin = User::forceCreate([
            'username' => 'hb_' . Str::lower(Str::random(8)), 'firstname' => 'Tes', 'lastname' => 'Admin',
            'email' => Str::lower(Str::random(10)) . '@example.test', 'password' => bcrypt('rahasia'),
            'email_verified_at' => now(), 'is_active' => true, 'push_enabled' => false,
        ]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $this->assertStringContainsString('Jadwal otomatis tidak berjalan', (string) SchedulerHeartbeat::warning());
        $this->assertStringContainsString('scripts/schedule-run.sh', (string) SchedulerHeartbeat::warning());

        SchedulerHeartbeat::beat();
        $this->assertNull(SchedulerHeartbeat::warning());

        // Lebih dari 15 menit tanpa jalan: berhenti.
        touch($this->file, now()->subMinutes(20)->getTimestamp());
        $this->assertStringContainsString('Berhenti sejak', (string) SchedulerHeartbeat::warning());
    }
}
