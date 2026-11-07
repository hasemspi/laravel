<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AccountAuthController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:registration')->post('/register', [RegistrationController::class, 'store']);
Route::middleware('throttle:login')->post('/login', [AccountAuthController::class, 'login']);
Route::middleware('throttle:admin-login')->post('/admin/login', [AdminAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active'])->get('/user', [AccountAuthController::class, 'me']);
Route::middleware('auth:sanctum')->post('/logout', [AccountAuthController::class, 'logout']);

Route::middleware(['auth:sanctum', 'role:student'])->get('/student/me', [AccountAuthController::class, 'me']);
Route::middleware(['auth:sanctum', 'role:trainer'])->get('/trainer/me', [AccountAuthController::class, 'me']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);
    Route::get('/admin/dashboard', AdminDashboardController::class);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy']);
});
