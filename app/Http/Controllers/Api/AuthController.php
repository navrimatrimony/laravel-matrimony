<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Mobile API Login (JSON + Sanctum Token)
     */
    public function login(Request $request)
    {
        // 1) Validate input (minimum required)
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

    

        // 2) Attempt login using web guard credentials
        if (!Auth::attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // 3) Authenticated user
        $user = $request->user();

        // 4) Create Sanctum token for mobile
        $token = $user->createToken('mobile-app')->plainTextToken;

        // 5) JSON success response (NO redirect)
        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ]);
    }
}
