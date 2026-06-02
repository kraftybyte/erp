<?php

namespace App\Filament\Widgets;

use App\Models\PaymentAccount;
use App\Models\Transaction;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget\Card;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class BudgetUtilization extends BaseWidget
{
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        return true;
    }

    protected function getCards(): array
    {
        $accounts = PaymentAccount::active()->get();

        // Calculate total balance from all accounts
        $totalBalance = $accounts->sum(fn($account) => $account->getAvailableBalance());

        // Calculate total transactions this month
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $monthlyIn = Transaction::whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('type', 'in')
            ->sum('amount');

        $monthlyOut = Transaction::whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->where('type', 'out')
            ->sum('amount');

        $monthlyNet = $monthlyIn - $monthlyOut;

        // Total initial balance (total deposited)
        $totalInitial = $accounts->sum('initial_balance');

        // Total in all time
        $totalIn = $accounts->sum(fn($account) => $account->getTotalIn());

        // Total out all time
        $totalOut = $accounts->sum(fn($account) => $account->getTotalOut());

        // Account count
        $accountCount = $accounts->count();

        // Low balance accounts (below 20% of initial or below 1M)
        $criticalCount = $accounts->filter(fn ($account) =>
            $account->getAvailableBalance() < 1000000 ||
            ($account->initial_balance > 0 && ($account->getAvailableBalance() / $account->initial_balance) < 0.2)
        )->count();

        return [
            Card::make('Total Saldo', 'Rp ' . number_format($totalBalance / 1000000, 1) . 'M')
                ->description($accountCount . ' rekening aktif')
                ->descriptionColor($totalBalance >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-wallet'),

            Card::make('Saldo Awal', 'Rp ' . number_format($totalInitial / 1000000, 1) . 'M')
                ->description('Total deposit')
                ->icon('heroicon-o-banknotes'),

            Card::make('Arus Bulan Ini', 'Rp ' . number_format(abs($monthlyNet) / 1000000, 1) . 'M')
                ->description(($monthlyNet >= 0 ? '+' : '-') . ' netto bulan ini')
                ->descriptionColor($monthlyNet >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-arrows-right-left'),

            Card::make('Total Pengeluaran', 'Rp ' . number_format($totalOut / 1000000, 1) . 'M')
                ->description($criticalCount . ' rekening kritis')
                ->descriptionColor($criticalCount > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-credit-card'),
        ];
    }
}
