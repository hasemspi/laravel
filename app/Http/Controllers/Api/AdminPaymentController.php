<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'verified', 'rejected', 'refunded'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        return response()->json(Payment::query()->with(['course:id,title,slug', 'user:id,name,email'])->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest()->paginate($filters['per_page'] ?? 20));
    }

    public function verify(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);
        $payment = DB::transaction(function () use ($request, $payment, $data) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') abort(422, 'Only pending payments can be verified.');
            $student = $payment->user()->lockForUpdate()->firstOrFail();
            if ($student->role !== 'student') abort(422, 'The payment is not associated with a student account.');
            $student->update(['status' => 'active']);
            $payment->update(['status' => 'verified', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'review_note' => $data['review_note'] ?? null]);
            Enrollment::query()->firstOrCreate(['user_id' => $student->id, 'course_id' => $payment->course_id], ['payment_id' => $payment->id, 'status' => 'active', 'enrolled_at' => now()]);
            AuditLog::query()->create(['user_id' => $request->user()->id, 'event' => 'payment.verified', 'auditable_type' => Payment::class, 'auditable_id' => $payment->id, 'metadata' => ['transaction_id' => $payment->transaction_id], 'ip_address' => $request->ip()]);
            return $payment->fresh(['user:id,name,email', 'course:id,title']);
        });
        return response()->json(['message' => 'Payment verified and enrollment activated.', 'payment' => $payment]);
    }

    public function reject(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['review_note' => ['required', 'string', 'max:1000']]);
        $payment = DB::transaction(function () use ($request, $payment, $data) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') abort(422, 'Only pending payments can be rejected.');
            $payment->update(['status' => 'rejected', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'review_note' => $data['review_note']]);
            AuditLog::query()->create(['user_id' => $request->user()->id, 'event' => 'payment.rejected', 'auditable_type' => Payment::class, 'auditable_id' => $payment->id, 'metadata' => ['transaction_id' => $payment->transaction_id], 'ip_address' => $request->ip()]);
            return $payment;
        });
        return response()->json(['message' => 'Payment rejected.', 'payment' => $payment]);
    }
}
