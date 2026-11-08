<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AccountAuthController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\AdminCourseController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PublicCourseController;
use App\Http\Controllers\Api\AdminPaymentController;
use App\Http\Controllers\Api\StudentLearningController;
use App\Http\Controllers\Api\AdminCategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:registration')->post('/register', [RegistrationController::class, 'store']);
Route::middleware('throttle:login')->post('/login', [AccountAuthController::class, 'login']);
Route::middleware('throttle:admin-login')->post('/admin/login', [AdminAuthController::class, 'login']);

Route::get('/courses', [PublicCourseController::class, 'index']);
Route::get('/courses/{course:slug}', [PublicCourseController::class, 'show']);
Route::get('/course-categories', [PublicCourseController::class, 'categories']);
Route::middleware('throttle:registration')->post('/courses/{course:slug}/checkout', [CheckoutController::class, 'store']);

Route::middleware(['auth:sanctum', 'active'])->get('/user', [AccountAuthController::class, 'me']);
Route::middleware('auth:sanctum')->post('/logout', [AccountAuthController::class, 'logout']);

Route::middleware(['auth:sanctum', 'role:student'])->get('/student/me', [AccountAuthController::class, 'me']);
Route::middleware(['auth:sanctum', 'role:student'])->get('/student/dashboard', [StudentLearningController::class, 'dashboard']);
Route::middleware(['auth:sanctum', 'role:student'])->get('/student/courses', [StudentLearningController::class, 'courses']);
Route::middleware(['auth:sanctum', 'role:student'])->get('/student/courses/{course}', [StudentLearningController::class, 'course']);
Route::middleware(['auth:sanctum', 'role:student'])->get('/student/lessons/{lesson}', [StudentLearningController::class, 'lesson']);
Route::middleware(['auth:sanctum', 'role:trainer'])->get('/trainer/me', [AccountAuthController::class, 'me']);

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);
    Route::get('/admin/dashboard', AdminDashboardController::class);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy']);
    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::get('/categories/{category}', [AdminCategoryController::class, 'show']);
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);
    Route::get('/admin/courses', [AdminCourseController::class, 'index']);
    Route::post('/admin/courses', [AdminCourseController::class, 'store']);
    Route::get('/admin/courses/{course}', [AdminCourseController::class, 'show']);
    Route::put('/admin/courses/{course}', [AdminCourseController::class, 'update']);
    Route::delete('/admin/courses/{course}', [AdminCourseController::class, 'destroy']);
    Route::get('/admin/payments', [AdminPaymentController::class, 'index']);
    Route::get('/admin/transactions', [AdminPaymentController::class, 'transactions']);
    Route::get('/admin/payment-reports', [AdminPaymentController::class, 'report']);
    Route::get('/admin/course-fees', [AdminPaymentController::class, 'fees']);
    Route::post('/admin/payments/{payment}/verify', [AdminPaymentController::class, 'verify']);
    Route::post('/admin/payments/{payment}/reject', [AdminPaymentController::class, 'reject']);
});
