<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
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
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['pending', 'verified', 'rejected', 'refunded'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->paymentsQuery($filters)->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function transactions(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function report(): JsonResponse
    {
        $payments = Payment::query();
        $statusTotals = Payment::query()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $monthly = collect(range(5, 0))->map(function (int $monthsAgo) {
            $start = now()->startOfMonth()->subMonths($monthsAgo);
            $end = $start->copy()->addMonth();
            $summary = Payment::query()->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->first();

            return ['month' => $start->format('M Y'), 'count' => (int) $summary->count, 'amount' => (float) $summary->amount];
        })->values();

        $recent = Payment::query()->with(['course:id,title,slug', 'user:id,name,email'])->latest()->limit(8)->get();

        return response()->json([
            'stats' => [
                'total_count' => (clone $payments)->count(),
                'total_amount' => (float) (clone $payments)->sum('amount'),
                'pending_count' => (int) ($statusTotals['pending']->count ?? 0),
                'pending_amount' => (float) ($statusTotals['pending']->amount ?? 0),
                'verified_count' => (int) ($statusTotals['verified']->count ?? 0),
                'verified_amount' => (float) ($statusTotals['verified']->amount ?? 0),
                'rejected_count' => (int) ($statusTotals['rejected']->count ?? 0),
                'refunded_count' => (int) ($statusTotals['refunded']->count ?? 0),
            ],
            'monthly' => $monthly,
            'recent_payments' => $recent,
        ]);
    }

    public function fees(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $courses = Course::query()
            ->select(['id', 'title', 'slug', 'price', 'discount_price', 'status', 'created_at', 'updated_at'])
            ->withCount('payments')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('updated_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json($courses);
    }

    public function verify(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);
        $payment = DB::transaction(function () use ($request, $payment, $data) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') abort(422, 'Only pending payments can be verified.');
            $student = $payment->user()->lockForUpdate()->first();
            if (! $student) abort(422, 'The payment is not linked to a student account.');
            if ($student->role !== 'student') abort(422, 'The payment is not associated with a student account.');
            $student->update(['status' => 'active']);
            $payment->update(['status' => 'verified', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'review_note' => $data['review_note'] ?? null]);
            Enrollment::query()->updateOrCreate(
                ['user_id' => $student->id, 'course_id' => $payment->course_id],
                ['payment_id' => $payment->id, 'status' => 'active', 'enrolled_at' => now(), 'completed_at' => null],
            );
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

    private function paymentsQuery(array $filters)
    {
        return Payment::query()
            ->with(['course:id,title,slug', 'user:id,name,email'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('student_name', 'like', "%{$search}%")
                        ->orWhere('student_email', 'like', "%{$search}%")
                        ->orWhere('student_mobile', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%")
                        ->orWhereHas('course', fn ($course) => $course->where('title', 'like', "%{$search}%"));
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('created_at');
    }
}
