<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutRequest;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function store(StoreCheckoutRequest $request, Course $course): JsonResponse
    {
        abort_unless($course->status === 'published', 404);
        $data = $request->validated();
        $payment = DB::transaction(function () use ($request, $course, $data) {
            $email = Str::lower($data['email']);
            $user = $request->user();
            if ($user && $user->email !== $email) abort(422, 'Use the email address associated with your signed-in account.');
            $user ??= User::query()->where('email', $email)->first();
            if (! $user && empty($data['password'])) abort(422, 'Set a password to create your student account.');
            if (! $user) {
                $user = User::query()->create(['name' => $data['name'], 'email' => $email, 'password' => $data['password'], 'role' => 'student', 'status' => 'pending']);
            }
            if ($user->role !== 'student') abort(422, 'This email belongs to a non-student account.');
            return Payment::query()->create([
                'user_id' => $user->id, 'course_id' => $course->id, 'student_name' => $data['name'],
                'student_email' => $email, 'student_mobile' => $data['mobile'], 'amount' => $course->discount_price ?? $course->price,
                'transaction_id' => $data['transaction_id'], 'status' => 'pending',
            ]);
        });
        return response()->json(['message' => 'Payment submitted for verification. Course access will begin after approval.', 'payment' => ['id' => $payment->id, 'status' => $payment->status]], 201);
    }
}
