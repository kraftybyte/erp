<?php

namespace Tests\Feature;

use App\Filament\Pages\ProjectTimelineBoard;
use App\Models\Project;
use App\Models\ProjectTimeline;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Board Project Timeline: data per periode (phase, task, terlambat), geser bar
 * tersimpan dengan tanggal inklusif, tambah/edit/hapus lewat modal, dan izin Shield.
 */
class ProjectTimelineBoardTest extends TestCase
{
    use DatabaseTransactions;

    private Project $project;

    private string $client;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo('2026-11-11 10:00:00');
        $this->actingAs($this->user('super_admin'));

        $this->client  = 'PT Uji Timeline ' . Str::upper(Str::random(6));
        $this->project = Project::create(['nama_project' => 'Booth Pameran', 'nama_perusahaan' => $this->client, 'status' => 'in_progress']);
    }

    public function test_data_board_per_periode_dengan_terlambat_dan_filter(): void
    {
        $late    = $this->phase('Design', '2026-11-03', '2026-11-07');
        $running = $this->phase('Shooting', '2026-11-10', '2026-11-14');
        $done    = $this->phase('Brief', '2026-11-01', '2026-11-02', 'completed');
        $nextMon = $this->phase('Editing', '2026-12-05', '2026-12-06');
        $october = $this->phase('Riset', '2026-10-20', '2026-10-25');
        $task    = Task::create(['nama_task' => 'Cetak backdrop', 'project_id' => $this->project->id, 'status' => 'process', 'proritas' => 'High', 'mulai_task' => '2026-11-12 09:00:00', 'selesai_task' => '2026-11-13 17:00:00']);

        $page = Livewire::test(ProjectTimelineBoard::class)->set('search', $this->client);
        $data = $page->instance()->boardData('2026-11-01', '2026-12-01');

        $this->assertCount(1, $data['resources']);
        $row = $data['resources'][0];
        $this->assertSame([$this->client, 'Booth Pameran', 5, 1, 2], [$row['title'], $row['subtitle'], $row['total'], $row['done'], $row['late']]);
        $this->assertSame(["phase-{$october->id}", "phase-{$late->id}", "phase-{$running->id}", "tasks-{$this->project->id}"], array_column($row['children'], 'id'),
            'Phase Oktober yang terlambat tetap tampil; yang selesai dan bulan depan tidak.');

        $events = collect($data['events'])->keyBy('id');
        $this->assertSame(['2026-11-03', '2026-11-08', 4], [$events["phase-{$late->id}"]['start'], $events["phase-{$late->id}"]['end'], $events["phase-{$late->id}"]['late']],
            'Ujung bar eksklusif: phase 3–7 Nov berakhir 8 Nov di kalender.');
        $this->assertContains('tl-late', $events["phase-{$late->id}"]['classNames']);
        $this->assertSame(['2026-11-08', '2026-11-12', 'background'], [$events["late-phase-{$late->id}"]['start'], $events["late-phase-{$late->id}"]['end'], $events["late-phase-{$late->id}"]['display']]);
        // Bar Oktober di luar periode: keterlambatannya tampil sebagai bar berlabel yang bisa diklik.
        $chip = $events["late-phase-{$october->id}"];
        $this->assertSame(['2026-11-01', '2026-11-12', 'Riset', 17, $october->id], [$chip['start'], $chip['end'], $chip['title'], $chip['late'], $chip['recordId']]);
        $this->assertContains('tl-late-chip', $chip['classNames']);
        $this->assertSame(0, $events["phase-{$running->id}"]['late']);
        $this->assertSame(['2026-11-12', '2026-11-14'], [$events["task-{$task->id}"]['start'], $events["task-{$task->id}"]['end']]);
        $this->assertFalse($events->has("phase-{$done->id}"));
        $this->assertFalse($events->has("phase-{$nextMon->id}"));
        $this->assertTrue($events["phase-{$late->id}"]['editable']);
        $this->assertStringContainsString('Terlambat 4 hari', $events["phase-{$late->id}"]['tip']);

        $this->assertSame(['running' => 1, 'overdue' => 2], $page->instance()->counts());

        $withDone = $page->set('showDone', true)->instance()->boardData('2026-11-01', '2026-12-01');
        $this->assertContains('tl-done', collect($withDone['events'])->firstWhere('id', "phase-{$done->id}")['classNames']);

        $overdue = $page->set('showDone', false)->set('focus', 'overdue')->instance()->boardData('2026-11-01', '2026-12-01');
        $this->assertSame(["phase-{$october->id}", "phase-{$late->id}"], array_column($overdue['resources'][0]['children'], 'id'));

        $noTasks = $page->set('focus', 'all')->set('showTasks', false)->instance()->boardData('2026-11-01', '2026-12-01');
        $this->assertNotContains("tasks-{$this->project->id}", array_column($noTasks['resources'][0]['children'], 'id'));
    }

    public function test_geser_bar_menyimpan_tanggal_inklusif(): void
    {
        $phase = $this->phase('Design', '2026-11-03', '2026-11-07');
        $task  = Task::create(['nama_task' => 'Cetak backdrop', 'project_id' => $this->project->id, 'status' => 'process', 'proritas' => 'High', 'mulai_task' => '2026-11-12 09:00:00', 'selesai_task' => '2026-11-13 17:00:00']);

        $page = Livewire::test(ProjectTimelineBoard::class);

        $this->assertSame(['ok' => true], $page->instance()->move("phase-{$phase->id}", '2026-11-04', '2026-11-09'));
        $phase->refresh();
        $this->assertSame(['2026-11-04', '2026-11-08', auth()->id()], [$phase->start_date->toDateString(), $phase->end_date->toDateString(), $phase->updated_by]);

        $this->assertSame(['ok' => true], $page->instance()->move("task-{$task->id}", '2026-11-20', '2026-11-22'));
        $task->refresh();
        $this->assertSame(['2026-11-20 09:00:00', '2026-11-21 17:00:00'], [(string) $task->mulai_task, (string) $task->selesai_task], 'Jam task tetap.');

        $this->assertSame(['ok' => false], $page->instance()->move("phase-{$phase->id}", '2026-11-09', '2026-11-04'));
        $this->assertSame(['ok' => false], $page->instance()->move('phase-999999999', '2026-11-04', '2026-11-05'));
        $this->assertSame('2026-11-04', $phase->fresh()->start_date->toDateString());
    }

    public function test_tanpa_izin_ubah_tidak_bisa_menggeser(): void
    {
        $phase = $this->phase('Design', '2026-11-03', '2026-11-07');

        $role = Role::create(['name' => 'Tes Lihat Timeline ' . Str::random(5), 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('page_ProjectTimelineBoard', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->user($role->name));

        $page = Livewire::test(ProjectTimelineBoard::class)->set('search', $this->client)->assertActionHidden('addPhase');

        $this->assertSame(['ok' => false], $page->instance()->move("phase-{$phase->id}", '2026-11-10', '2026-11-12'));
        $this->assertSame('2026-11-03', $phase->fresh()->start_date->toDateString());
        $this->assertFalse(collect($page->instance()->boardData('2026-11-01', '2026-12-01')['events'])->firstWhere('id', "phase-{$phase->id}")['editable']);
    }

    public function test_tambah_dari_kalender_lalu_edit_dan_hapus(): void
    {
        $page = Livewire::test(ProjectTimelineBoard::class)
            ->mountAction('addPhase', ['project_id' => $this->project->id, 'start' => '2026-11-16', 'end' => '2026-11-19'])
            // DatePicker non-native menyimpan state sementara sebagai tanggal-jam.
            ->assertActionDataSet([
                'project_id' => $this->project->id,
                'start_date' => fn ($state) => str_starts_with((string) $state, '2026-11-16'),
                'end_date'   => fn ($state) => str_starts_with((string) $state, '2026-11-18'),
                'status'     => 'ongoing',
            ])
            ->setActionData(['nama_phase' => 'Revisi'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertDispatched('timeline-refresh');

        $phase = ProjectTimeline::where('project_id', $this->project->id)->sole();
        $this->assertSame(['Revisi', '2026-11-16', '2026-11-18', ProjectTimeline::colorFor('Revisi')], [$phase->nama_phase, $phase->start_date->toDateString(), $phase->end_date->toDateString(), $phase->color]);

        Livewire::test(ProjectTimelineBoard::class)
            ->callAction('editPhase', data: ['end_date' => '2026-11-15'], arguments: ['id' => $phase->id])
            ->assertHasActionErrors(['end_date' => 'after_or_equal']);

        $page = Livewire::test(ProjectTimelineBoard::class)
            ->callAction('editPhase', data: ['nama_phase' => 'Design', 'status' => 'completed'], arguments: ['id' => $phase->id])
            ->assertHasNoActionErrors();
        $phase->refresh();
        $this->assertSame(['Design', 'completed', '#ff5915'], [$phase->nama_phase, $phase->status, $phase->color]);

        $page->callAction(['editPhase', 'deletePhase'], arguments: ['editPhase' => ['id' => $phase->id]]);
        $this->assertNull(ProjectTimeline::find($phase->id));
    }

    public function test_halaman_tampil_dan_route_lama_dihapus(): void
    {
        $this->get(ProjectTimelineBoard::getUrl())
            ->assertOk()
            ->assertSee('Tambah phase')
            ->assertSee('Berjalan hari ini')
            ->assertSee('Terlambat');

        $this->post('/timeline/update', ['id' => 1, 'start' => '2026-11-01', 'end' => '2026-11-02'])->assertNotFound();
    }

    public function test_status_done_lama_disamakan(): void
    {
        $phase = $this->phase('Design', '2026-11-03', '2026-11-07', 'done');

        (require database_path('migrations/2026_11_11_130000_normalize_project_timeline_status.php'))->up();

        $this->assertSame('completed', $phase->fresh()->status);
    }

    private function phase(string $name, string $start, string $end, string $status = 'ongoing'): ProjectTimeline
    {
        return ProjectTimeline::create([
            'project_id' => $this->project->id,
            'nama_phase' => $name,
            'start_date' => $start,
            'end_date'   => $end,
            'status'     => $status,
            'color'      => ProjectTimeline::colorFor($name),
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'          => 'tl_' . Str::lower(Str::random(8)),
            'firstname'         => 'Tes',
            'lastname'          => 'Timeline',
            'email'             => Str::lower(Str::random(10)) . '@example.test',
            'password'          => bcrypt('rahasia'),
            'email_verified_at' => now(),
            'is_active'         => true,
            'push_enabled'      => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
