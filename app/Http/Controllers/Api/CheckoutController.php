<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutRequest;
use App\Models\Course;
use App\Models\Enrollment;
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
            $user = $request->user()
                ? User::query()->lockForUpdate()->findOrFail($request->user()->id)
                : User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user && $user->email !== $email) abort(422, 'Use the email address associated with your signed-in account.');
            if (! $user && empty($data['password'])) abort(422, 'Set a password to create your student account.');
            if (! $user) {
                $user = User::query()->create(['name' => $data['name'], 'email' => $email, 'password' => $data['password'], 'role' => 'student', 'status' => 'pending']);
            }
            if ($user->role !== 'student') abort(422, 'This email belongs to a non-student account.');

            $enrollment = Enrollment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();
            if ($enrollment?->status === 'active') abort(422, 'You already have access to this course.');
            if ($enrollment?->status === 'completed') abort(422, 'You have already completed this course.');

            $latestPayment = Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($latestPayment?->status === 'pending') abort(422, 'A payment for this course is already pending verification.');
            if ($latestPayment?->status === 'verified' && $enrollment?->status !== 'cancelled') abort(422, 'This course payment has already been verified.');

            return Payment::query()->create([
                'user_id' => $user->id, 'course_id' => $course->id, 'student_name' => $data['name'],
                'student_email' => $email, 'student_mobile' => $data['mobile'], 'amount' => $course->discount_price ?? $course->price,
                'transaction_id' => $data['transaction_id'], 'status' => 'pending',
            ]);
        });
        return response()->json(['message' => 'Payment submitted for verification. Course access will begin after approval.', 'payment' => ['id' => $payment->id, 'status' => $payment->status]], 201);
    }
}
