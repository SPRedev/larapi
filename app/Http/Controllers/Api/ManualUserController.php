<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken; // We need this to look up the token
use App\Models\User; // We need this to look up the user

class ManualUserController extends Controller

{
    /**
     * Manually authenticates a token and changes the user's password.
     * This method does NOT use Laravel's built-in auth guards.
     */
    public function changePassword(Request $request)
    {
        // --- Step A: Manual Authentication ---

        // 1. Get the token from the 'Authorization: Bearer <token>' header.
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json(['error' => 'Authentication token not provided.'], 401);
        }

        // 2. Find the token in the database.
        // Sanctum stores a SHA-256 hash of the token, so we can't do a direct lookup.
        // We must find the token by its ID, which is the part before the '|'.
        $tokenParts = explode('|', $bearerToken, 2);
        $tokenId = $tokenParts[0];
        $tokenInstance = PersonalAccessToken::find($tokenId);

        // 3. Verify the token exists, is valid, and matches the plain-text token.
        if (!$tokenInstance || !hash_equals($tokenInstance->token, hash('sha256', $tokenParts[1]))) {
            return response()->json(['error' => 'Invalid authentication token.'], 401);
        }

        // 4. Manually load the user from the 'users' table using the ID from the token.
        $user = User::find($tokenInstance->tokenable_id);

        if (!$user) {
            // This would happen if the user was deleted but the token still exists.
            return response()->json(['error' => 'User associated with this token not found.'], 401);
        }

        // --- End of Manual Authentication ---
        // If we get here, $user is a valid, authenticated user object.


        // --- Step B: Password Change Logic (Same as before) ---

        // 1. Validate the input
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required', 'string', 'confirmed',
                Password::min(8)->mixedCase()->numbers()
            ],
        ]);

        // 2. Verify the user's CURRENT password against the user we just loaded.
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['error' => 'The provided current password does not match our records.'], 422);
        }

        // 3. Generate the TWO new password hashes
        $laravelHash = Hash::make($validated['new_password']);
        $phpassHasher = new \Hautelook\Phpass\PasswordHash(8, true);
        $phpassHash = $phpassHasher->HashPassword($validated['new_password']);

        // 4. Update BOTH tables in a database transaction
        DB::transaction(function () use ($user, $laravelHash, $phpassHash) {
            DB::table('users')->where('id', $user->id)->update(['password' => $laravelHash]);
            DB::table('app_entity_1')->where('id', $user->id)->update(['password' => $phpassHash]);
        });

        // 5. Return a success response
        return response()->json(['message' => 'Password changed successfully.']);
    }
}
