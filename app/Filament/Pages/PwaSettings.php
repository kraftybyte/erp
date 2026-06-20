<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Actions;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class PwaSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'PWA & Notifications';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.pwa-settings';

    public static function getNavigationLabel(): string
    {
        return \App\Helpers\NavigationHelper::getLabel('pwa_settings', 'PWA & Notifications');
    }

    public array $pushSettings = [];
    public bool $isSubscribed = false;
    public ?string $pushStatus = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->pushSettings = [
            'expense_approvals' => true,
            'absence_requests' => true,
            'project_updates' => true,
            'task_reminders' => true,
            'announcements' => true,
        ];

        $this->isSubscribed = $user->push_enabled ?? false;
        $this->pushStatus = $this->isSubscribed ? 'Aktif' : 'Nonaktif';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Push Notifications')
                    ->description('Atur notifikasi push untuk perangkat mobile')
                    ->schema([
                        Forms\Components\Toggle::make('push_enabled')
                            ->label('Aktifkan Notifikasi Push')
                            ->helperText('Terima notifikasi langsung di perangkat Anda')
                            ->afterStateUpdated(function ($state) {
                                if ($state) {
                                    $this->subscribeToPush();
                                } else {
                                    $this->unsubscribeFromPush();
                                }
                            }),

                        Forms\Components\Placeholder::make('status')
                            ->label('Status')
                            ->content(fn () => $this->pushStatus),
                    ]),

                Forms\Components\Section::make('Notifikasi yang Diterima')
                    ->schema([
                        Forms\Components\Checkbox::make('push_expense_approvals')
                            ->label('Persetujuan Expense')
                            ->default(true),

                        Forms\Components\Checkbox::make('push_absence_requests')
                            ->label('Pengajuan Cuti/Izin')
                            ->default(true),

                        Forms\Components\Checkbox::make('push_project_updates')
                            ->label('Update Project')
                            ->default(true),

                        Forms\Components\Checkbox::make('push_task_reminders')
                            ->label('Pengingat Task')
                            ->default(true),

                        Forms\Components\Checkbox::make('push_announcements')
                            ->label('Pengumuman Company')
                            ->default(true),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('test_notification')
                ->label('Kirim Test Notifikasi')
                ->icon('heroicon-o-bell')
                ->action(fn () => $this->sendTestNotification()),
        ];
    }

    public function subscribeToPush(): void
    {
        $this->pushStatus = 'Mengkonfigurasi...';
    }

    public function unsubscribeFromPush(): void
    {
        $user = auth()->user();
        $user->update(['push_enabled' => false]);
        $this->pushStatus = 'Nonaktif';
        $this->isSubscribed = false;
    }

    public function sendTestNotification(): void
    {
        $user = auth()->user();
        $subscription = $user->getPushSubscription();

        if (!$subscription) {
            $this->pushStatus = 'Belum subscribe - aktifkan push terlebih dahulu';
            return;
        }

        $this->dispatch('send-test-push');

        \Filament\Notifications\Notification::make()
            ->title('Test Notifikasi Dikirim')
            ->body('Periksa perangkat Anda untuk notifikasi test.')
            ->success()
            ->send();
    }
}