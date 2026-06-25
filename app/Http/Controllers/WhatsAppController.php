<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    /**
     * Inbound registration from WhatsApp Engine (JSON + X-API-KEY).
     * Uses {@see User::$fillable} {@code mobile} for the WhatsApp phone value (request field {@code phone}).
     */
    public function registerUser(Request $request): JsonResponse
    {
        $expected = config('services.whatsapp_engine.api_key');
        if (filled($expected) && $request->header('X-API-KEY') !== $expected) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'phone' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $phone = $request->input('phone');

        $user = User::where('mobile', $phone)->first();

        if ($user) {
            return response()->json([
                'status' => 'exists',
                'message' => 'User already exists',
            ]);
        }

        $user = User::create([
            'name' => $request->input('name') ?? '',
            'mobile' => $phone,
            'password' => '123456',
            'email' => null,
        ]);

        app(WhatsAppService::class)->send(
            $user->mobile,
            '🎉 Your profile is successfully created on Suchak Matrimony!'
        );

        return response()->json([
            'status' => 'created',
            'user_id' => $user->id,
        ]);
    }
}
