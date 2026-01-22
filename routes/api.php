<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

//======================================================================
// GUEST ROUTES (No Authentication Required)
//======================================================================
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

    return response()->json(['token' => $token->plainTextToken]);
});

Route::get('/statuses', [TaskController::class, 'getStatuses']);


//======================================================================
// AUTHENTICATED ROUTES (Requires Sanctum Token)
//======================================================================
Route::middleware('auth:sanctum')->group(function () {
    
    // --- User Info ---
    Route::get('/user', function (Request $request) {
        $rukoUser = DB::table('app_entity_1')
            ->where('id', $request->user()->id)
            ->select('id', 'field_12 as username')
            ->first();
        return response()->json($rukoUser);
    });

    // --- Tasks ---
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'createTask']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::put('/tasks/{task}', [TaskController::class, 'updateTask']);
    Route::delete('/tasks/{task}', [TaskController::class, 'deleteTask']);
    Route::post('/tasks/{task}/update-status', [TaskController::class, 'updateTaskStatus']);
    
    // --- Comments ---
    Route::post('/tasks/{task}/comments', [TaskController::class, 'createComment']);
    Route::put('/comments/{comment}', [TaskController::class, 'updateComment']);
    Route::delete('/comments/{comment}', [TaskController::class, 'deleteComment']);

    // --- Notifications & Form Data ---
    Route::get('/notifications', [TaskController::class, 'getNotifications']);
    Route::get('/form-data/create-task', [TaskController::class, 'getCreateTaskFormData']);

});
