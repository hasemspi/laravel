<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $announcements = $this->visibleQuery($user)
            ->with(['course:id,title', 'reads' => fn ($query) => $query->where('user_id', $user->id)])
            ->latest('created_at')
            ->get()
            ->map(fn (Announcement $announcement) => $this->present($announcement))
            ->values();

        return response()->json(['announcements' => $announcements, 'unread_count' => $this->unreadCountFor($user)]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->unreadCountFor($request->user())]);
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($announcement->status === 'published' && $this->visibleQuery($request->user())->whereKey($announcement->id)->exists(), 404);
        AnnouncementRead::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $request->user()->id],
            ['read_at' => now()],
        );

        return response()->json(['message' => 'Announcement marked as read.', 'unread_count' => $this->unreadCountFor($request->user())]);
    }

    private function visibleQuery(User $user)
    {
        $query = Announcement::query()->where('status', 'published');

        if ($user->role === 'student') {
            return $query->where(function ($nested) use ($user) {
                $nested->where('audience_type', 'students')
                    ->orWhere(function ($courseAnnouncement) use ($user) {
                        $courseAnnouncement->where('audience_type', 'course')
                            ->whereHas('course.enrollments', function ($enrollment) use ($user) {
                                $enrollment->where('user_id', $user->id)
                                    ->whereIn('status', ['active', 'completed'])
                                    ->whereHas('payment', fn ($payment) => $payment->where('status', 'verified'));
                            });
                    });
            });
        }

        return $query->where('audience_type', 'trainers');
    }

    private function unreadCountFor(User $user): int
    {
        return (int) $this->visibleQuery($user)->whereDoesntHave('reads', fn ($read) => $read->where('user_id', $user->id))->count();
    }

    private function present(Announcement $announcement): array
    {
        $read = $announcement->reads->first();
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'type' => $announcement->type,
            'audience_type' => $announcement->audience_type,
            'course' => $announcement->course,
            'description' => $announcement->description,
            'external_url' => $announcement->external_url,
            'status' => $announcement->status,
            'published_at' => $announcement->created_at?->toISOString(),
            'is_read' => (bool) $read,
            'read_at' => $read?->read_at?->toISOString(),
        ];
    }
}
