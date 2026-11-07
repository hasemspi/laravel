<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $month = now()->startOfMonth()->subMonths(5);
        $studentRegistrations = [];

        for ($offset = 0; $offset < 6; $offset++) {
            $start = $month->copy()->addMonths($offset);
            $end = $start->copy()->addMonth();

            $studentRegistrations[] = [
                'label' => $start->format('M'),
                'students' => User::query()
                    ->where('role', 'student')
                    ->where('created_at', '>=', $start)
                    ->where('created_at', '<', $end)
                    ->count(),
            ];
        }

        $recentStudents = User::query()
            ->select(['id', 'name', 'email', 'status', 'created_at'])
            ->where('role', 'student')
            ->latest('created_at')
            ->limit(6)
            ->get();

        return response()->json([
            'stats' => [
                'total_users' => User::query()->count(),
                'students' => User::query()->where('role', 'student')->count(),
                'active_students' => User::query()->where('role', 'student')->where('status', 'active')->count(),
                'pending_approvals' => User::query()->where('status', 'pending')->count(),
                'trainers' => User::query()->where('role', 'trainer')->count(),
                'administrators' => User::query()->where('role', 'admin')->count(),
                'courses' => Course::query()->count(),
                'active_enrollments' => Enrollment::query()->where('status', 'active')->count(),
                'pending_payments' => Payment::query()->where('status', 'pending')->count(),
                'verified_payments' => Payment::query()->where('status', 'verified')->count(),
                'total_revenue' => Payment::query()->where('status', 'verified')->sum('amount'),
            ],
            'student_registrations' => $studentRegistrations,
            'recent_students' => $recentStudents,
            'data_availability' => [
                'users' => true,
                'courses' => true,
                'enrollments' => true,
                'finance' => true,
                'cms' => false,
            ],
        ]);
    }
}
