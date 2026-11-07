<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['user_id', 'course_id', 'student_name', 'student_email', 'student_mobile', 'amount', 'payment_method', 'transaction_id', 'status', 'verified_by', 'verified_at', 'review_note'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'verified_at' => 'datetime']; }
    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
