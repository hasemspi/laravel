<?php

namespace App\Services;

use App\Models\CourseBatch;
use App\Models\Enrollment;
use Illuminate\Support\Collection;

class BatchAccessService
{
    public function accessibleBatches(Enrollment $enrollment): Collection
    {
        $enrollment->loadMissing(['course.accessRule', 'batchAccess']);
        $rule = $enrollment->course->accessRule;
        $explicit = $enrollment->batchAccess
            ->whereNull('revoked_at')
            ->keyBy('course_batch_id');

        return CourseBatch::query()
            ->where('course_id', $enrollment->course_id)
            ->where('status', 'published')
            ->with(['trainers:id,name,email'])
            ->with(['modules' => fn ($query) => $query->orderBy('sort_order')])
            ->orderByDesc('release_date')
            ->get()
            ->filter(fn (CourseBatch $batch) => $explicit->has($batch->id) || $this->matchesRule($enrollment, $batch, $rule))
            ->values();
    }

    public function hasAccess(Enrollment $enrollment, CourseBatch $batch): bool
    {
        if ((int) $batch->course_id !== (int) $enrollment->course_id || $enrollment->status === 'cancelled') {
            return false;
        }

        $explicit = $enrollment->batchAccess()
            ->where('course_batch_id', $batch->id)
            ->whereNull('revoked_at')
            ->exists();

        if ($explicit && $batch->status === 'published') return true;

        $enrollment->loadMissing('course.accessRule');
        return $batch->status === 'published' && $this->matchesRule($enrollment, $batch, $enrollment->course->accessRule);
    }

    private function matchesRule(Enrollment $enrollment, CourseBatch $batch, $rule): bool
    {
        if (!$rule || !$rule->lifetime_access_enabled || !$rule->future_batch_access) {
            return false;
        }

        $releaseDate = $batch->release_date;
        if ($releaseDate && $releaseDate->isFuture()) {
            return false;
        }

        if ($rule->release_rule === 'all_published') {
            return true;
        }

        return $releaseDate && $releaseDate->greaterThanOrEqualTo($enrollment->enrolled_at);
    }
}
