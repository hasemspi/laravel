<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    private const ROLES = ['admin', 'student', 'trainer'];

    private const STATUSES = ['active', 'inactive', 'pending'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'role' => ['sometimes', 'nullable', Rule::in(self::ROLES)],
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'status', 'created_at'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'role' => ['required', Rule::in(self::ROLES)],
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->safeUser($user),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:72'],
            'role' => ['sometimes', 'required', Rule::in(self::ROLES)],
            'status' => ['sometimes', 'required', Rule::in(self::STATUSES)],
        ]);

        $newRole = $validated['role'] ?? $user->role;
        $newStatus = $validated['status'] ?? $user->status;

        if ($this->wouldRemoveLastActiveAdmin($request, $user, $newRole, $newStatus)) {
            return response()->json(['message' => 'The last active administrator cannot be removed or deactivated.'], 422);
        }

        if (isset($validated['password']) && $validated['password'] !== '') {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (isset($validated['email'])) {
            $validated['email'] = strtolower($validated['email']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->safeUser($user->refresh()),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($this->wouldRemoveLastActiveAdmin($request, $user, null, null, true)) {
            return response()->json(['message' => 'The last active administrator cannot be removed or deactivated.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    private function wouldRemoveLastActiveAdmin(
        Request $request,
        User $user,
        ?string $newRole,
        ?string $newStatus,
        bool $deleting = false,
    ): bool {
        $isActiveAdmin = $user->role === 'admin' && $user->status === 'active';

        if (!$isActiveAdmin) {
            return false;
        }

        if (!$deleting && $newRole === 'admin' && $newStatus === 'active') {
            return false;
        }

        if ((int) $request->user()->id === (int) $user->id) {
            return true;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->whereKeyNot($user->id)
            ->count() === 0;
    }

    private function safeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'created_at' => $user->created_at,
        ];
    }
}
