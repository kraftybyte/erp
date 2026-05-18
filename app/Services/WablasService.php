<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WablasService
{
    /**
     * Kirim pesan ke nomor melalui API Wablas
     *
     * @param string $phone
     * @param string $message
     * @return mixed
     */
    public function sendMessage($phone, $message)
    {
        // Pastikan nomor telepon sudah diformat dengan benar
        $phone = $this->formatPhoneNumber($phone);

        // URL API Wablas
        $url = 'https://tegal.wablas.com/api/send-message';

        // API Key Wablas
        $apiKey = config('services.wablas.api_key');

        // Kirim permintaan POST ke API Wablas
        try {
            $response = Http::withHeaders([
                'Authorization' => $apiKey
            ])->post($url, [
                'phone' => $phone,
                'message' => $message
            ]);

            // Cek apakah pengiriman pesan berhasil atau gagal
            $responseData = $response->json();
            
            if ($response->failed() || $responseData['status'] !== 'success') {
                Log::error('Wablas API Error', [
                    'response' => $responseData,
                    'request' => ['phone' => $phone, 'message' => $message]
                ]);
                return false;
            }

            // Jika berhasil, log respons sukses
            Log::info("Message sent successfully to {$phone}: " . json_encode($responseData));

            return $responseData;
        } catch (\Exception $e) {
            // Menangani error yang mungkin terjadi saat API call
            Log::error("Error sending message via Wablas API: " . $e->getMessage(), [
                'phone' => $phone,
                'message' => $message
            ]);
            return false;
        }
    }

    /**
     * Format nomor telepon agar sesuai dengan standar
     *
     * @param string $phone
     * @return string
     */
    private function formatPhoneNumber($phone)
    {
        // Hapus karakter selain angka dan pastikan nomor mulai dengan '62' untuk Indonesia
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            return '62' . substr($phone, 1);  // Mengganti '0' dengan '62'
        }

        return $phone;
    }
}
