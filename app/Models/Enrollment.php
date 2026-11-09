<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    protected $fillable = ['user_id', 'course_id', 'payment_id', 'status', 'enrolled_at', 'completed_at'];
    protected function casts(): array { return ['enrolled_at' => 'datetime', 'completed_at' => 'datetime']; }
    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function batchAccess(): HasMany { return $this->hasMany(EnrollmentBatchAccess::class); }
}
