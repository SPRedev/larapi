<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Auth; // <-- Add this
use Illuminate\Validation\ValidationException; // <-- And this
use Illuminate\Support\Facades\DB;
// --- START: ADD THIS NEW LOGIN ROUTE ---
Route::post('/login', function (Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (! Auth::attempt($credentials)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials do not match our records.'],
        ]);
    }

    $user = Auth::user();
    $token = $user->createToken('api-token');

    return response()->json([
        'token' => $token->plainTextToken
    ]);
});
// --- END: ADD THIS NEW LOGIN ROUTE ---


// This is the default route that comes with Laravel
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $userId = $request->user()->id; // Get the authenticated user's ID

    // Fetch the user's details from the Rukovoditel users table (app_entity_1)
    $rukoUser = DB::table('app_entity_1')
        ->where('id', $userId)
        ->select('id', 'field_7 as firstname', 'field_8 as lastname', 'field_9 as email', 'field_12 as username')
        ->first();

    if (!$rukoUser) {
        return response()->json(['error' => 'User not found in Rukovoditel'], 404);
    }

    return response()->json($rukoUser);
});

// This is our new route for getting tasks. It IS protected by authentication.
Route::middleware('auth:sanctum')->get('/tasks', [TaskController::class, 'index']);

// routes/api.php

// Add this new route for getting a single task by its ID
Route::middleware('auth:sanctum')->get('/tasks/{task_id}', [TaskController::class, 'show']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
// In routes/api.php

// Add this new route for getting notifications
Route::middleware('auth:sanctum')->get('/notifications', [TaskController::class, 'getNotifications']);

// In routes/api.php
Route::middleware('auth:sanctum')->get('/tasks/{task_id}/download-attachment/{filename}', [TaskController::class, 'downloadAttachment']);
// In routes/api.php
Route::get('/statuses', [TaskController::class, 'getStatuses']);
// In routes/api.php
Route::middleware('auth:sanctum')->post('/tasks/{task_id}/update-status', [TaskController::class, 'updateStatus']);
// In routes/api.php

// Route to create a new comment on a task
Route::middleware('auth:sanctum')->post('/tasks/{task_id}/comments', [TaskController::class, 'createComment']);

// Route to update an existing comment
Route::middleware('auth:sanctum')->put('/comments/{comment_id}', [TaskController::class, 'updateComment']);
// In routes/api.php
Route::middleware('auth:sanctum')->delete('/comments/{comment_id}', [TaskController::class, 'deleteComment']);
// In routes/api.php

// NEW: Route to get data needed for the create task form
Route::middleware('auth:sanctum')->get('/form-data/create-task', [TaskController::class, 'getCreateTaskFormData']);

// Route to actually create the task
Route::middleware('auth:sanctum')->post('/tasks', [TaskController::class, 'createTask']);
