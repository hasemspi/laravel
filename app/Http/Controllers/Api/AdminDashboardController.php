<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
            ],
            'student_registrations' => $studentRegistrations,
            'recent_students' => $recentStudents,
            'data_availability' => [
                'users' => true,
                'courses' => false,
                'enrollments' => false,
                'finance' => false,
                'cms' => false,
            ],
        ]);
    }
}
