<?php

namespace Tests\Feature;

use App\Filament\Resources\AdvanceResource\Pages\ListAdvances;
use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\Advance;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\RealisasiService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * Tombol Export Excel di tabel Expense dan Advance. Suite berjalan di database
 * yang sudah berisi data, jadi data tes diberi tanggal 3 Feb 2001 dan tabel
 * difilter ke tanggal itu.
 */
class ExpenseAdvanceExportTest extends TestCase
{
    use DatabaseTransactions;

    private const DAY = '2001-02-03';

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'username'     => 'exp_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => 'Export',
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $this->user->assignRole('super_admin');

        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->project = Project::create(['nama_project' => 'Booth Export', 'nama_perusahaan' => 'PT Export', 'status' => 'in_progress']);
    }

    public function test_export_expense_mengikuti_filter_tabel(): void
    {
        $paid     = $this->expense('Dibayarkan', 150_000, ['paid' => 150_000, 'keterangan_expense' => '=1+1 grab ke klien']);
        $pending  = $this->expense('Pending', 200_000);
        $rejected = $this->expense('Ditolak', 50_000, ['rejection_reason' => 'Nota tidak jelas']);
        $this->expense('Pending', 999_000, ['submission_date' => '2001-03-01']);

        $book  = $this->download(Livewire::test(ListExpenses::class)->filterTable('submission_date', ['from' => self::DAY, 'until' => self::DAY]), 'Expense');
        $sheet = $book->getSheetByName('Expense');

        $this->assertSame(['Expense', 'Ringkasan'], $book->getSheetNames());
        $this->assertStringContainsString('Dari 3 Feb 2001', $sheet->getCell('A2')->getValue());
        $this->assertSame('ID', $sheet->getCell('A5')->getValue());

        $rows = $this->rowsById($sheet, 3);
        $this->assertEqualsCanonicalizing([$paid->id, $pending->id, $rejected->id], array_keys($rows));

        $row = $rows[$paid->id];
        $this->assertSame('PT Export | Booth Export', $sheet->getCell("D{$row}")->getValue());
        $this->assertEquals(150_000, $sheet->getCell("G{$row}")->getValue());
        $this->assertEquals(150_000, $sheet->getCell("I{$row}")->getValue());
        $this->assertSame('"Rp "#,##0', $sheet->getStyle("G{$row}")->getNumberFormat()->getFormatCode());
        $this->assertStringContainsString("/admin/expenses/{$paid->id}", $sheet->getCell("A{$row}")->getHyperlink()->getUrl());

        // Keterangan yang diawali "=" tetap teks, bukan rumus.
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell("F{$row}")->getDataType());
        $this->assertSame('=1+1 grab ke klien', $sheet->getCell("F{$row}")->getValue());

        $this->assertNull($sheet->getCell("I{$rows[$pending->id]}")->getValue(), 'Yang belum dibayar tidak punya nominal dibayar.');
        $this->assertSame('Nota tidak jelas', $sheet->getCell("N{$rows[$rejected->id]}")->getValue());

        $this->assertSame('Total', $sheet->getCell('A9')->getValue());
        $this->assertEquals(400_000, $sheet->getCell('G9')->getCalculatedValue());
        $this->assertEquals(150_000, $sheet->getCell('I9')->getCalculatedValue());

        // Ringkasan per status memuat yang ditolak; per kategori tidak.
        $summary = $this->summaryRows($book->getSheetByName('Ringkasan'));
        $this->assertEquals([1, 50_000, 0], $summary['Per status']['Ditolak']);
        $this->assertEquals([2, 350_000, 150_000], $summary['Per kategori (tanpa yang ditolak)']['Transport']);
    }

    public function test_export_advance_dengan_realisasi(): void
    {
        $open = $this->advance(Advance::STATUS_DIBAYARKAN, 1_000_000);
        app(RealisasiService::class)->createBatch($open, ['uploaded_by' => $this->user->id], [['name' => 'Lighting', 'quantity' => 2, 'price' => 200_000]]);
        $pending = $this->advance(Advance::STATUS_PENDING, 700_000);

        $book  = $this->download(Livewire::test(ListAdvances::class)->filterTable('submission_date', ['from' => self::DAY, 'until' => self::DAY]), 'Advance');
        $sheet = $book->getSheetByName('Advance');

        $this->assertSame(['Advance', 'Realisasi', 'Ringkasan'], $book->getSheetNames());

        $rows = $this->rowsById($sheet, 2);
        $this->assertEqualsCanonicalizing([$open->id, $pending->id], array_keys($rows));

        $row = $rows[$open->id];
        $this->assertEquals(1_000_000, $sheet->getCell("I{$row}")->getValue(), 'Dicairkan');
        $this->assertEquals(400_000, $sheet->getCell("J{$row}")->getValue(), 'Direalisasi');
        $this->assertEquals(600_000, $sheet->getCell("L{$row}")->getValue(), 'Belum dipertanggungjawabkan');
        $this->assertNull($sheet->getCell("I{$rows[$pending->id]}")->getValue());
        $this->assertEquals(1_700_000, $sheet->getCell('G8')->getCalculatedValue());

        $realisasi = $book->getSheetByName('Realisasi');
        $this->assertEquals($open->id, $realisasi->getCell('A6')->getValue());
        $this->assertSame('Lighting', $realisasi->getCell('F6')->getValue());
        $this->assertEquals(2, $realisasi->getCell('H6')->getValue());
        $this->assertEquals(400_000, $realisasi->getCell('J6')->getValue());

        $summary = $this->summaryRows($book->getSheetByName('Ringkasan'));
        // Realisasi pertama mengubah status advance-nya.
        $this->assertEquals([1, 1_000_000, 1_000_000, 400_000, 0, 600_000], $summary['Per status'][$open->fresh()->status]);
        $this->assertEquals([2, 1_700_000, 1_000_000, 400_000, 0, 600_000], $summary['Per project (tanpa yang ditolak)']['PT Export | Booth Export']);
    }

    private function download(Testable $component, string $name): Spreadsheet
    {
        $component->callAction('export')->assertFileDownloaded($name . ' ' . now()->format('Y-m-d') . '.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'tes-export-');
        file_put_contents($path, base64_decode($component->effects['download']['content']));

        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @return array<int, int> ID => nomor baris
     */
    private function rowsById(Worksheet $sheet, int $expected): array
    {
        $rows = [];

        for ($row = 6; $row < 6 + $expected; $row++) {
            $rows[(int) $sheet->getCell("A{$row}")->getValue()] = $row;
        }

        $this->assertSame('Total', $sheet->getCell('A' . (6 + $expected))->getValue(), "Tepat {$expected} baris data.");

        return $rows;
    }

    /**
     * @return array<string, array<string, array<int, float>>> bagian => label => [jumlah, ...nominal]
     */
    private function summaryRows(Worksheet $sheet): array
    {
        $sections = [];
        $section  = null;

        foreach ($sheet->toArray(null, true, false) as $values) {
            $label = $values[0] ?? null;

            if (str_starts_with((string) $label, 'Per ')) {
                $section = $label;
            } elseif ($section && $label !== null && is_numeric($values[1] ?? null)) {
                $sections[$section][$label] = array_map('floatval', array_values(array_filter(array_slice($values, 1), fn ($v) => $v !== null)));
            }
        }

        return $sections;
    }

    private function expense(string $status, int $amount, array $attributes = []): Expense
    {
        return Expense::create($attributes + [
            'user_id'            => $this->user->id,
            'submission_date'    => self::DAY,
            'project_id'         => $this->project->id,
            'category'           => 'Transport',
            'keterangan_expense' => "Tes {$status}",
            'amount'             => $amount,
            'paid'               => 0,
            'status'             => $status,
        ]);
    }

    private function advance(string $status, int $amount): Advance
    {
        return Advance::create([
            'user_id'            => $this->user->id,
            'submission_date'    => self::DAY,
            'project_id'         => $this->project->id,
            'category'           => 'Project Support',
            'keterangan_advance' => "Tes {$status}",
            'amount'             => $amount,
            'paid'               => $status === Advance::STATUS_PENDING ? 0 : $amount,
            'status'             => $status,
        ]);
    }
}
