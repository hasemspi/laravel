<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AccountAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:150'],
            'password' => ['required', 'string', 'max:72'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();
        $validRole = $user && in_array($user->role, ['admin', 'student', 'trainer'], true);
        $passwordMatches = $user && Hash::check($credentials['password'], $user->password);

        if (!$passwordMatches || !$validRole || $user->status !== 'active') {
            return response()->json(['message' => 'The provided credentials are invalid.'], 401);
        }

        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'user' => $this->publicUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->publicUser($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'nickname' => ['nullable', 'string', 'max:120'],
            'profile_photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user->update(['nickname' => filled($data['nickname'] ?? null) ? trim($data['nickname']) : null]);

        if ($request->hasFile('profile_photo')) {
            if (str_starts_with((string) $user->profile_image_path, 'storage/')) {
                Storage::disk('public')->delete(substr($user->profile_image_path, strlen('storage/')));
            }

            $path = $request->file('profile_photo')->store('student-avatars', 'public');
            $user->update(['profile_image_path' => 'storage/'.$path]);
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => $this->publicUser($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'The current password is incorrect.');
        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'role' => $user->role,
            'username' => str($user->email)->before('@')->toString(),
            'student_id' => $user->role === 'student' ? 'WEB6-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT) : null,
            'phone_number' => $user->role === 'student' ? $user->payments()->latest('id')->value('student_mobile') : null,
            'profile_image_url' => $user->profile_image_path
                ? url('/'.ltrim($user->profile_image_path, '/'))
                : null,
        ];
    }
}
