<?php

namespace App\Helpers;

class ColorHelper
{
    /**
     * Get status color based on standard semantics
     */
    public static function status(string $status): string
    {
        return match (strtolower($status)) {
            // Pending/Waiting
            'pending', 'menunggu', 'negosiasi', 'draft', 'todo', 'on_hold', 'hold' => 'warning',

            // Approved/Success/Completed
            'approved', 'disetujui', 'selesai', 'completed', 'done', 'menang', 'success' => 'success',

            // Rejected/Error/Cancelled
            'rejected', 'ditolak', 'canceled', 'kalah', 'danger' => 'danger',

            // In Progress/Process
            'in_progress', 'process', 'processing', 'dalam_project', 'on_progress' => 'info',

            // Realized by User - waiting for Finance verification
            'sudah di-realisasi user' => 'info',

            // Paid - advance paid but not yet realized
            'dibayarkan' => 'gray',

            // Default/Neutral
            default => 'gray',
        };
    }

    /**
     * Get absence category color
     */
    public static function absenceCategory(string $category): string
    {
        return match ($category) {
            'cuti' => 'success',
            'wfa' => 'warning',
            'tukar_hari' => 'primary',
            'sakit' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Get absence status color
     */
    public static function absenceStatus(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => 'warning',
            'approved', 'disetujui' => 'success',
            'rejected', 'ditolak' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Get deal status color
     */
    public static function dealStatus(string $status): string
    {
        return match ($status) {
            'Negosiasi' => 'gray',
            'Menunggu Pembayaran' => 'warning',
            'Dalam Tahap Project' => 'info',
            'Selesai' => 'success',
            'Hold' => 'secondary',
            default => 'gray',
        };
    }

    /**
     * Get leads status color
     */
    public static function leadsStatus(string $status): string
    {
        return match ($status) {
            'Dalam Komunikasi' => 'gray',
            'Proposal Sudah Dikirim' => 'warning',
            'Menang' => 'success',
            'Kalah' => 'danger',
            'Converted' => 'info',
            default => 'gray',
        };
    }

    /**
     * Get task status color
     */
    public static function taskStatus(string $status): string
    {
        return match (strtolower($status)) {
            'todo', 'pending' => 'gray',
            'in_progress', 'process' => 'info',
            'review' => 'warning',
            'done', 'completed' => 'success',
            'hold' => 'secondary',
            default => 'gray',
        };
    }

    /**
     * Get quotation status color
     */
    public static function quotationStatus(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'expired' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get project status color
     */
    public static function projectStatus(string $status): string
    {
        return match (strtolower($status)) {
            'on_progress', 'in_progress' => 'info',
            'completed' => 'success',
            'cancelled' => 'danger',
            'on_hold', 'hold' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Get token status color
     */
    public static function tokenStatus(bool $isExpired, int $daysUntilExpiry = 30): string
    {
        if ($isExpired || $daysUntilExpiry <= 0) {
            return 'danger';
        }
        if ($daysUntilExpiry <= 7) {
            return 'warning';
        }
        return 'success';
    }

    /**
     * Get performance score color
     */
    public static function performanceScore(float $score): string
    {
        if ($score >= 90) return 'success';
        if ($score >= 75) return 'info';
        if ($score >= 60) return 'warning';
        if ($score >= 40) return 'warning';
        return 'danger';
    }

    /**
     * Get contract type color
     */
    public static function contractType(string $type): string
    {
        return match ($type) {
            'editor' => 'primary',
            'file' => 'warning',
            default => 'gray',
        };
    }
}