<?php

namespace App\Helpers;

use App\Settings\GeneralSettings;

/**
 * Helper untuk mengambil navigation label dari GeneralSettings
 */
class NavigationHelper
{
    /**
     * Get navigation label dari settings
     */
    public static function getLabel(string $key, ?string $default = null): string
    {
        $settings = app(GeneralSettings::class);
        $property = 'nav_' . $key;

        return $settings->{$property} ?? $default ?? self::getDefaultLabel($key);
    }

    /**
     * Get default label
     */
    private static function getDefaultLabel(string $key): string
    {
        $defaults = [
            'leads' => 'Leads',
            'client' => 'Client',
            'product' => 'Product',
            'quotation' => 'Quotation',
            'contract' => 'Contract',
            'project' => 'Project',
            'task_kanban' => 'Task Kanban',
            'task_list' => 'Task List',
            'timeline' => 'Timeline',
            'expense' => 'Expense',
            'advance' => 'Advance',
            'payment_account' => 'Payment Account',
            'invoice' => 'Invoice',
            'analytics' => 'Analytics',
            'employee' => 'Employee',
            'absence' => 'Absence',
            'event' => 'Event',
            'social_account' => 'Social Account',
            'connected_account' => 'Connected Account',
            'meta_ad' => 'Meta Ad Account',
            'user_management' => 'User Management',
            'general_settings' => 'General Settings',
            'mail_settings' => 'Mail Settings',
            'pwa_settings' => 'PWA Settings',
        ];

        return $defaults[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Get all navigation labels
     */
    public static function getAllLabels(): array
    {
        $settings = app(GeneralSettings::class);

        return [
            'leads' => $settings->nav_leads ?? 'Leads',
            'client' => $settings->nav_client ?? 'Client',
            'product' => $settings->nav_product ?? 'Product',
            'quotation' => $settings->nav_quotation ?? 'Quotation',
            'contract' => $settings->nav_contract ?? 'Contract',
            'project' => $settings->nav_project ?? 'Project',
            'task_kanban' => $settings->nav_task_kanban ?? 'Task Kanban',
            'task_list' => $settings->nav_task_list ?? 'Task List',
            'timeline' => $settings->nav_timeline ?? 'Timeline',
            'expense' => $settings->nav_expense ?? 'Expense',
            'advance' => $settings->nav_advance ?? 'Advance',
            'payment_account' => $settings->nav_payment_account ?? 'Payment Account',
            'invoice' => $settings->nav_invoice ?? 'Invoice',
            'analytics' => $settings->nav_analytics ?? 'Analytics',
            'employee' => $settings->nav_employee ?? 'Employee',
            'absence' => $settings->nav_absence ?? 'Absence',
            'event' => $settings->nav_event ?? 'Event',
            'social_account' => $settings->nav_social_account ?? 'Social Account',
            'connected_account' => $settings->nav_connected_account ?? 'Connected Account',
            'meta_ad' => $settings->nav_meta_ad ?? 'Meta Ad Account',
            'user_management' => $settings->nav_user_management ?? 'User Management',
            'general_settings' => $settings->nav_general_settings ?? 'General Settings',
            'mail_settings' => $settings->nav_mail_settings ?? 'Mail Settings',
            'pwa_settings' => $settings->nav_pwa_settings ?? 'PWA Settings',
        ];
    }
}
