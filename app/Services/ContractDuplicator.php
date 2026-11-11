<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Deal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Duplikat kontrak: semua isi (pasal, ruang lingkup bertingkat, Tabel A/B,
 * lampiran, kop surat, penanda tangan) disalin ke kontrak baru dengan nomor
 * baru, lalu data klien diganti sesuai pilihan. File (kop surat, PDF kontrak)
 * dipakai bersama: Filament tidak menghapus file lama saat diganti di form.
 */
class ContractDuplicator
{
    /** Kolom data klien yang ikut berganti saat kontrak untuk klien lain. */
    public const CLIENT_DETAILS = ['client_address', 'client_phone', 'client_email', 'client_signatory_name', 'client_signatory_title'];

    /**
     * @param  array{contract_number?: ?string, client_name_full?: ?string, deal_id?: ?int, copy_client_details?: bool, effective_date?: ?string}  $options
     *   deal_id: Deal kontrak baru (null = tanpa deal); bila berbeda dari kontrak asal,
     *            data klien (nama, alamat, telepon, email, PIC) diisi dari Deal itu;
     *   copy_client_details false: alamat, telepon, email, dan penanda tangan klien dikosongkan;
     *   effective_date: tanggal berakhir ikut bergeser dengan durasi yang sama.
     */
    public function duplicate(Contract $source, array $options = []): Contract
    {
        return DB::transaction(function () use ($source, $options): Contract {
            $copy = $source->replicate();
            $copy->contract_number = filled($options['contract_number'] ?? null) ? $options['contract_number'] : Contract::generateContractNumber();

            $this->applyDates($copy, $source, $options['effective_date'] ?? null);
            $this->applyClient($copy, $source, $options);

            $copy->save();
            $this->copyItems($source, $copy);

            return $copy;
        });
    }

    private function applyDates(Contract $copy, Contract $source, ?string $effective): void
    {
        if (blank($effective)) {
            return;
        }

        $start = Carbon::parse($effective)->startOfDay();
        $copy->effective_date = $start;

        if ($source->effective_date && $source->expiration_date) {
            $copy->expiration_date = $this->sameDuration($source->effective_date, $source->expiration_date, $start);
        }
    }

    /**
     * Tanggal berakhir dengan durasi yang sama. Kontrak per bulan (1 Jan–31 Des
     * = 12 bulan) tetap per bulan (1 Des–30 Nov); selain itu jumlah harinya sama.
     */
    private function sameDuration(Carbon $from, Carbon $to, Carbon $start): Carbon
    {
        $from   = $from->copy()->startOfDay();
        $to     = $to->copy()->startOfDay();
        $months = (int) $from->diffInMonths($to->copy()->addDay());

        if ($months > 0 && $from->copy()->addMonthsNoOverflow($months)->subDay()->isSameDay($to)) {
            return $start->copy()->addMonthsNoOverflow($months)->subDay();
        }

        if ($months > 0 && $from->copy()->addMonthsNoOverflow($months)->isSameDay($to)) {
            return $start->copy()->addMonthsNoOverflow($months);
        }

        return $start->copy()->addDays((int) $from->diffInDays($to));
    }

    private function applyClient(Contract $copy, Contract $source, array $options): void
    {
        $deal = filled($options['deal_id'] ?? null) ? Deal::find($options['deal_id']) : null;

        if (array_key_exists('deal_id', $options)) {
            $copy->deal_id = $deal?->getKey();
        }

        // Deal lain = klien lain: data klien dari Deal itu. Deal sama: data salinan tetap.
        if ($deal && (int) $deal->getKey() !== (int) $source->deal_id) {
            $copy->forceFill([
                'client_name_full'       => $deal->nama_perusahaan,
                'client_address'         => $deal->alamat,
                'client_phone'           => $deal->telephone,
                'client_email'           => $deal->email,
                'client_signatory_name'  => $deal->nama_pic,
                'client_signatory_title' => null,
            ]);
        } elseif (! ($options['copy_client_details'] ?? true)) {
            $copy->forceFill(array_fill_keys(self::CLIENT_DETAILS, null));
        }

        if (filled($options['client_name_full'] ?? null)) {
            $copy->client_name_full = $options['client_name_full'];
        }
    }

    /** Semua baris kontrak; parent_id (kategori → sub-item) dipetakan ke baris salinan. */
    private function copyItems(Contract $source, Contract $copy): void
    {
        $pending = ContractItem::where('contract_id', $source->getKey())->orderBy('sort_order')->orderBy('id')->get();
        $newIds  = [];

        // Induk dulu, lalu anaknya (berapa pun tingkatnya).
        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn (ContractItem $item) => $item->parent_id === null || isset($newIds[$item->parent_id]) || ! $pending->contains('id', $item->parent_id));

            // Data rusak (induk saling merujuk): sisanya disalin tanpa induk, bukan berputar terus.
            if ($ready->isEmpty()) {
                $ready = $pending;
            }

            foreach ($ready as $item) {
                $clone = $item->replicate();
                $clone->contract_id = $copy->getKey();
                $clone->parent_id   = $item->parent_id === null ? null : ($newIds[$item->parent_id] ?? null);
                $clone->save();

                $newIds[$item->getKey()] = $clone->getKey();
            }

            $pending = $pending->reject(fn (ContractItem $item) => isset($newIds[$item->getKey()]));
        }
    }
}
