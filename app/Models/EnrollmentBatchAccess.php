<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentBatchAccess extends Model
{
    protected $table = 'enrollment_batch_access';
    protected $fillable = ['enrollment_id', 'course_batch_id', 'access_source', 'granted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo { return $this->belongsTo(Enrollment::class); }
    public function batch(): BelongsTo { return $this->belongsTo(CourseBatch::class, 'course_batch_id'); }
}
