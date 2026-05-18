<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\WablasService;
use Illuminate\Support\Facades\Log;

class WablasController extends Controller
{
    protected $wablasService;

    public function __construct(WablasService $wablasService)
    {
        $this->wablasService = $wablasService;
    }

    public function sendMessage(Request $request)
    {
        // Validasi input
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^62\d{9,12}$/'],  // Validasi nomor telepon Indonesia
            'message' => 'required|string',
        ]);

        $phone = $request->input('phone');
        $message = $request->input('message');

        // Log untuk memantau data yang dikirim
        Log::info('Sending message via Wablas', [
            'phone' => $phone,
            'message' => $message
        ]);

        // Mengirim pesan melalui layanan Wablas
        $response = $this->wablasService->sendMessage($phone, $message);

        // Menangani respons dari Wablas
        if (!$response) {
            Log::error('Wablas API error', ['phone' => $phone, 'message' => $message]);
            return response()->json(['error' => 'Failed to send message'], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'WhatsApp message sent successfully',
            'response' => $response
        ]);
    }
}
