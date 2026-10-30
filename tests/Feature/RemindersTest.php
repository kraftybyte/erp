<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Leads;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MorningBrief;
use App\Settings\IntegrationSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengingat terjadwal: ringkasan pagi, deadline task/project, dan follow-up lead.
 */
class RemindersTest extends TestCase
{
    use DatabaseTransactions;

    private string $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Di-fake, bukan dibaca dari database: isian Settings → Integrasi di mesin lokal tidak boleh mengubah hasil tes.
        IntegrationSettings::fake([
            'telegram_bot_token' => '111:PENGAJUAN',
            'finance_bot_token'  => '222:KEUANGAN',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->client = 'PT Pengingat ' . Str::upper(Str::random(5));
        $this->owner  = $this->user('Staff');
    }

    public function test_ringkasan_pagi_berisi_yang_perlu_diingatkan(): void
    {
        $this->invoice(['nama_perusahaan' => "{$this->client} Telat", 'grand_total' => 8_000_000, 'paid' => 3_000_000, 'status' => 'partial', 'due_date' => today()->subDays(10)]);
        $this->invoice(['nama_perusahaan' => "{$this->client} Minggu Ini", 'grand_total' => 2_000_000, 'due_date' => today()->addDays(3)]);
        // Tidak diingatkan: sudah lunas, dibatalkan.
        $this->invoice(['nama_perusahaan' => "{$this->client} Lunas", 'grand_total' => 1_000_000, 'paid' => 1_000_000, 'status' => 'paid', 'due_date' => today()->subDays(10)]);
        $this->invoice(['nama_perusahaan' => "{$this->client} Batal", 'grand_total' => 1_000_000, 'status' => 'cancelled', 'due_date' => today()->subDays(10)]);

        $this->contract("{$this->client} Kontrak Habis", today()->addDays(20));
        $this->contract("{$this->client} Kontrak Lama", today()->addDays(60));
        $this->contract("{$this->client} Kontrak Dihapus", today()->addDays(10), ['deleted_at' => now()]);

        $this->task("Task {$this->client} telat", today()->subDays(2));
        $this->task("Task {$this->client} besok", today()->addDay());
        $this->task("Task {$this->client} sudah selesai", today()->subDay(), 'done');

        $brief = app(MorningBrief::class);
        $data  = $brief->data();

        // Hanya data tes ini, supaya isi database lokal tidak menggeser daftar yang ditampilkan.
        $mine = fn (string $key, string $field) => $data[$key]->filter(fn ($row) => str_contains((string) $row->{$field}, $this->client))->values();
        $html = $brief->telegram([
            'overdue'    => $mine('overdue', 'nama_perusahaan'),
            'due_soon'   => $mine('due_soon', 'nama_perusahaan'),
            'contracts'  => $mine('contracts', 'client_name_full'),
            'late_tasks' => $mine('late_tasks', 'nama_task'),
            'week_tasks' => $mine('week_tasks', 'nama_task'),
        ] + $data);

        $this->assertStringContainsString("{$this->client} Telat — <b>Rp 5.000.000</b> · lewat 10 hari", $html);
        $this->assertStringContainsString("{$this->client} Minggu Ini — <b>Rp 2.000.000</b>", $html);
        $this->assertStringNotContainsString("{$this->client} Lunas", $html);
        $this->assertStringNotContainsString("{$this->client} Batal", $html);

        $this->assertStringContainsString("{$this->client} Kontrak Habis — " . today()->addDays(20)->locale('id')->translatedFormat('j M') . ' (20 hari lagi)', $html);
        $this->assertStringNotContainsString('Kontrak Lama', $html);
        $this->assertStringNotContainsString('Kontrak Dihapus', $html);

        $this->assertStringContainsString("Task {$this->client} telat — " . today()->subDays(2)->locale('id')->translatedFormat('j M') . ' (lewat 2 hari)', $html);
        $this->assertStringContainsString("Task {$this->client} besok — besok", $html);
        $this->assertStringNotContainsString('sudah selesai', $html);

        $this->assertStringContainsString('invoice lewat jatuh tempo', $brief->summary($data));
    }

    public function test_ringkasan_pagi_dikirim_ke_telegram_keuangan_dan_lonceng(): void
    {
        $this->invoice(['grand_total' => 4_000_000, 'due_date' => today()->subDays(3)]);
        $finance = $this->user('Finance', '700000001');
        $staff   = $this->user('Staff', '700000002');

        $this->artisan('ringkasan:pagi')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot222:KEUANGAN/sendMessage')
            && $request['chat_id'] === '700000001'
            && str_contains($request['text'], 'Ringkasan pagi')
            && str_contains($request['text'], 'Invoice lewat jatuh tempo'));
        Http::assertNotSent(fn (Request $request) => $request['chat_id'] === '700000002');

        $this->assertSame(1, $this->notifications($finance, 'Ringkasan pagi'));
        $this->assertSame(0, $this->notifications($staff, 'Ringkasan pagi'));
    }

    public function test_tanpa_bot_keuangan_ringkasan_lewat_bot_pengajuan(): void
    {
        IntegrationSettings::fake(['telegram_bot_token' => '111:PENGAJUAN', 'finance_bot_token' => null]);
        config(['services.telegram.finance_bot_token' => null]);
        $this->invoice(['grand_total' => 4_000_000, 'due_date' => today()->subDays(3)]);
        $this->user('Director', '700000003');

        $this->artisan('ringkasan:pagi')->assertSuccessful();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot111:PENGAJUAN/sendMessage') && $request['chat_id'] === '700000003');
    }

    public function test_deadline_task_dan_project_diingatkan_lewat_lonceng_dan_telegram(): void
    {
        $designer = $this->user('Staff', '700000004');
        $creator  = $this->user('Staff');
        $director = $this->user('Director');

        $task = $this->task("Desain {$this->client}", today()->addDay(), creator: $creator);
        $task->users()->attach($designer);
        $this->task("Selesai {$this->client}", today()->addDay(), 'done')->users()->attach($designer);

        $project = Project::create(['nama_project' => "Booth {$this->client}", 'nama_perusahaan' => $this->client, 'status' => 'in_progress', 'timeline_mulai' => today(), 'timeline_selesai' => today()->addDays(2)]);
        Project::create(['nama_project' => "Arsip {$this->client}", 'nama_perusahaan' => $this->client, 'status' => 'completed', 'timeline_mulai' => today(), 'timeline_selesai' => today()->addDay()]);

        $this->artisan('deadline:reminder')->assertSuccessful();

        $this->assertSame(1, $this->notifications($designer, 'Deadline task', "Task \"Desain {$this->client}\" deadline besok"));
        $this->assertSame(0, $this->notifications($designer, 'Deadline task', "Selesai {$this->client}"));
        $this->assertSame(1, $this->notifications($creator, 'Deadline task', "Desain {$this->client}"), 'Pembuat task ikut diingatkan.');
        $this->assertSame(1, $this->notifications($director, 'Deadline project', "Booth {$this->client}\" ({$this->client}) deadline dalam 2 hari"));
        $this->assertSame(0, $this->notifications($director, 'Deadline project', "Arsip {$this->client}"));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'bot111:PENGAJUAN/sendMessage')
            && $request['chat_id'] === '700000004'
            && str_contains($request['text'], "Desain {$this->client}"));
        $this->assertNotNull($project->fresh());
    }

    public function test_lead_follow_up_satu_pengingat_per_orang(): void
    {
        $sales = $this->user('Sales');

        foreach (['Budi', 'Sari'] as $pic) {
            $lead = Leads::create(['nama_pic' => "{$pic} {$this->client}", 'nama_perusahaan' => $this->client, 'telephone' => '0812000000', 'source' => 'Instagram', 'status' => 'Dalam Komunikasi', 'is_converted' => false]);
            DB::table('leads')->where('id', $lead->id)->update(['updated_at' => now()->subDays(5)]);
        }

        $this->artisan('leads:follow-up-reminder')->assertSuccessful();

        $rows = DB::table('notifications')->where('notifiable_id', $sales->id)->get();
        $this->assertCount(1, $rows, 'Satu ringkasan, bukan satu notifikasi per lead.');
        $body = json_decode($rows->first()->data, true)['body'];
        $this->assertStringContainsString("Budi {$this->client} ({$this->client}) — Dalam Komunikasi, 5 hari tanpa update", $body);
        $this->assertStringContainsString("Sari {$this->client}", $body);
    }

    public function test_pengingat_terjadwal(): void
    {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event) => [Str::after($event->command, 'artisan\' ') => $event->expression]);

        $this->assertSame('0 8 * * 1-5', $events['ringkasan:pagi']);
        $this->assertSame('0 8 * * *', $events['deadline:reminder']);
        $this->assertSame('0 9 * * 1-5', $events['leads:follow-up-reminder']);
    }

    // =================================================================
    // Helper
    // =================================================================

    private function notifications(User $user, string $title, string $body = ''): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('data->title', $title)
            ->get()
            ->filter(fn ($row) => str_contains(json_decode($row->data, true)['body'], $body))
            ->count();
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create($attributes + [
            'uuid'            => 'TES/' . Str::upper(Str::random(8)),
            'user_id'         => $this->owner->id,
            'type'            => 'sale',
            'type_payment'    => 'full_payment',
            'status'          => 'draft',
            'nama_perusahaan' => $this->client,
            'date'            => today()->subMonth(),
            'paid'            => 0,
        ]);
    }

    private function contract(string $client, $expires, array $attributes = []): Contract
    {
        return Contract::forceCreate($attributes + [
            'contract_number'  => 'TES/' . Str::random(6),
            'client_name_full' => $client,
            'effective_date'   => today()->subYear(),
            'expiration_date'  => $expires,
        ]);
    }

    private function task(string $name, $deadline, string $status = 'process', ?User $creator = null): Task
    {
        return Task::create([
            'nama_task'    => $name,
            'status'       => $status,
            'mulai_task'   => today()->subWeek(),
            'selesai_task' => $deadline,
            'proritas'     => 'High',
            'creator_id'   => $creator?->id,
        ]);
    }

    private function user(string $role, ?string $chatId = null): User
    {
        $user = User::forceCreate([
            'username'          => 'ingat_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => $role,
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'email_verified_at' => now(),
            'is_active'         => true,
            'push_enabled'      => false,
            'telegram_chat_id'  => $chatId,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
