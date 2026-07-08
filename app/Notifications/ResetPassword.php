<?php

namespace App\Notifications;

use App\Settings\GeneralSettings;
use Illuminate\Auth\Notifications\ResetPassword as BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends BaseNotification
{
    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $settings = app(GeneralSettings::class);
        $appName = $settings->app_name ?? 'ERP Digitalk';

        $resetUrl = url(route('filament.admin.auth.password-reset.reset', [
            'email' => $notifiable->email,
            'token' => $this->token,
        ], false));

        return (new MailMessage)
            ->subject('Reset Password - ' . $appName)
            ->greeting('Halo!')
            ->line('Anda menerima email ini karena kami menerima permintaan reset password untuk akun Anda.')
            ->action('Reset Password', $resetUrl)
            ->line('Link ini akan kadaluarsa dalam 60 menit.')
            ->line('Jika Anda tidak meminta reset password, abaikan email ini.');
    }
}
