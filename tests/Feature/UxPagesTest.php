<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Filament\Resources\ExpenseResource\Pages\ViewExpense;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\PaymentAccountResource\Pages\ListPaymentAccounts;
use App\Filament\Resources\ProjectResource\Pages\ListProjects;
use App\Helpers\Money;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\ActionGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perbaikan tampilan dan alur di halaman daftar dan detail.
 */
class UxPagesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = $this->user('super_admin');
        $this->actingAs($this->admin);
    }

    public function test_selesaikan_expense_hanya_setelah_dibayarkan(): void
    {
        $pending = $this->expense('Pending');
        $dibayar = $this->expense('Dibayarkan');

        Livewire::test(ListExpenses::class)
            ->assertTableActionHidden('selesai', $pending)    // dulu tampil: bisa ditutup tanpa disetujui
            ->assertTableActionVisible('selesai', $dibayar);
    }

    public function test_view_expense_tampil_dengan_tanggal_dan_nominal_indonesia(): void
    {
        $expense = $this->expense('Ditolak', ['rejection_reason' => 'Nota tidak terbaca', 'reference' => 'TRF-77']);

        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertOk()
            ->assertSee('Rp 1.250.000')
            ->assertSee('Nota tidak terbaca')
            ->assertSee('TRF-77')
            ->assertSee('Belum ada lampiran.');
    }

    public function test_deadline_project_selesai_tidak_disebut_terlambat(): void
    {
        $selesai = Project::create(['nama_project' => 'Neon Box', 'nama_perusahaan' => 'PT Lama', 'status' => 'completed', 'timeline_selesai' => now()->subDays(30)]);
        $telat   = Project::create(['nama_project' => 'Booth', 'nama_perusahaan' => 'PT Baru', 'status' => 'in_progress', 'timeline_selesai' => now()->subDays(3)]);

        Livewire::test(ListProjects::class)
            ->assertTableColumnStateSet('deadline', 'Selesai', $selesai)
            ->assertTableColumnStateSet('deadline', '3 hari terlambat', $telat);
    }

    public function test_total_masuk_dan_keluar_rekening_terisi(): void
    {
        $rekening = PaymentAccount::create(['name' => 'Kas Tes ' . Str::random(4), 'bank_name' => 'BCA', 'account_number' => '123', 'initial_balance' => 0, 'is_active' => true]);
        foreach ([['in', 500_000], ['out', 200_000]] as [$type, $amount]) {
            Transaction::create([
                'payment_account_id' => $rekening->id, 'created_by' => $this->admin->id, 'type' => $type, 'amount' => $amount,
                'description' => 'Tes', 'transaction_date' => now()->toDateString(), 'category' => 'other',
            ]);
        }

        // Dulu kedua kolom selalu kosong: atribut transactions_sum_* tidak pernah dimuat.
        Livewire::test(ListPaymentAccounts::class)
            ->assertTableColumnStateSet('transactions_sum_in', 500_000, $rekening)
            ->assertTableColumnStateSet('transactions_sum_out', 200_000, $rekening)
            ->assertSee('Rp 500.000');
    }

    public function test_view_invoice_memakai_rupiah_dan_label_indonesia(): void
    {
        $invoice = Invoice::create([
            'uuid' => 'TES/' . Str::upper(Str::random(8)), 'user_id' => $this->admin->id, 'type' => 'sale',
            'nama_perusahaan' => 'PT Uji Tampilan', 'nama_pic' => 'Budi', 'date' => now(), 'due_date' => now()->addWeek(),
            'paid' => 0, 'grand_total' => 2_500_000,
        ]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertOk()
            ->assertSee('Ditagihkan kepada')
            ->assertSee('u.p. Budi')
            ->assertSee('Rp 2.500.000')
            ->assertDontSee('2,500,000.00');
    }

    public function test_menu_titik_tiga_menampilkan_label_aksi(): void
    {
        // iconButton() di dalam ActionGroup mengalahkan tampilan dropdown: menu
        // Invoice dulu hanya berisi ikon tanpa teks.
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $index = $resource::getPages()['index'] ?? null;
            if (! $index || ! is_subclass_of($index->getPage(), ListRecords::class)) {
                continue;
            }

            $table = Livewire::test($index->getPage())->instance()->getTable();

            foreach ($table->getActions() as $group) {
                if (! $group instanceof ActionGroup) {
                    continue;
                }

                foreach ($group->getActions() as $action) {
                    $this->assertSame($action::GROUPED_VIEW, $action->getView(), "{$resource}: aksi {$action->getName()}");
                }
            }
        }
    }

    public function test_rupiah_bertanda_untuk_saldo_dan_transaksi_keluar(): void
    {
        $this->assertSame('Rp 1.250.000', Money::signed(1_250_000));
        $this->assertSame('-Rp 1.250.000', Money::signed(-1_250_000));
        $this->assertSame('Rp 1.250.000', Money::rupiah(-1_250_000));
        $this->assertSame('Rp 4.500.000', Money::inCurrency(4_500_000, null));
        $this->assertSame('USD 1.250,50', Money::inCurrency(1250.5, 'usd'));
    }

    // =================================================================
    // Helper
    // =================================================================

    private function expense(string $status, array $extra = []): Expense
    {
        return Expense::create($extra + [
            'user_id'            => $this->admin->id,
            'submission_date'    => today(),
            'category'           => 'Transport',
            'keterangan_expense' => 'Tes UX ' . Str::random(4),
            'amount'             => 1_250_000,
            'status'             => $status,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::forceCreate([
            'username'     => 'ux_' . Str::lower(Str::random(8)),
            'firstname'    => 'Tes',
            'lastname'     => $role,
            'email'        => Str::lower(Str::random(10)) . '@example.test',
            'password'     => bcrypt('rahasia'),
            'is_active'    => true,
            'push_enabled' => false,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
