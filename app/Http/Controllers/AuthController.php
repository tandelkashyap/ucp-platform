<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Deliberately minimal — this is not a Fortify/Breeze replacement. No email
 * verification, password reset, or 2FA. It exists so a frontend has
 * something to authenticate against; swap it for a real starter kit
 * whenever that work happens, rather than building those pieces by hand.
 */
class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        $user = User::create($validated); // 'hashed' cast on User handles the password

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'token' => $user->createToken('dashboard')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Same error either way — don't let the response reveal whether
        // the email exists at all.
        abort_unless(
            $user && Hash::check($validated['password'], $user->password),
            422,
            "Those credentials don't match our records."
        );

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'token' => $user->createToken('dashboard')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
