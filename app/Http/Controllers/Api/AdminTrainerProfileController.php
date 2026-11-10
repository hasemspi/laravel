<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTrainerProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $trainers = User::query()
            ->where('role', 'trainer')
            ->with('trainerProfile')
            ->withCount(['assignedCourses as published_courses_count' => fn ($query) => $query->where('status', 'published')])
            ->when($request->string('search')->trim()->toString(), function ($query, $search) {
                $query->where(fn ($nested) => $nested->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
            })
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        $trainers->getCollection()->transform(fn (User $trainer) => [
            'id' => $trainer->id,
            'name' => $trainer->name,
            'email' => $trainer->email,
            'status' => $trainer->status,
            'published_courses_count' => (int) $trainer->published_courses_count,
            'profile' => $trainer->trainerProfile,
        ]);

        return response()->json($trainers);
    }

    public function verify(Request $request, TrainerProfile $trainerProfile): JsonResponse
    {
        $validated = $request->validate(['is_verified' => ['required', 'boolean']]);
        $isVerified = (bool) $validated['is_verified'];

        $trainerProfile->update([
            'is_verified' => $isVerified,
            'verified_at' => $isVerified ? now() : null,
            'verified_by_id' => $isVerified ? $request->user()->id : null,
        ]);

        return response()->json([
            'message' => $isVerified ? 'Trainer credentials verified.' : 'Trainer verification removed.',
            'profile' => $trainerProfile->fresh(['user']),
        ]);
    }
}
