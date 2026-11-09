<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseBatch;
use App\Models\Enrollment;
use App\Models\EnrollmentBatchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEnrollmentAccessController extends Controller
{
    public function grant(Request $request, Enrollment $enrollment, CourseBatch $batch): JsonResponse
    {
        abort_unless((int) $enrollment->course_id === (int) $batch->course_id, 422, 'The enrollment and batch belong to different courses.');
        $access = EnrollmentBatchAccess::query()->updateOrCreate(
            ['enrollment_id' => $enrollment->id, 'course_batch_id' => $batch->id],
            ['access_source' => 'manual', 'granted_at' => now(), 'revoked_at' => null],
        );

        return response()->json(['message' => 'Batch access granted.', 'access' => $access->load('batch')]);
    }

    public function revoke(Request $request, Enrollment $enrollment, CourseBatch $batch): JsonResponse
    {
        $access = EnrollmentBatchAccess::query()->where('enrollment_id', $enrollment->id)->where('course_batch_id', $batch->id)->firstOrFail();
        $access->update(['revoked_at' => now()]);
        return response()->json(['message' => 'Batch access revoked.', 'access' => $access]);
    }
}
