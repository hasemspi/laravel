<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchLesson extends Model
{
    protected $fillable = ['batch_module_id', 'title', 'description', 'thumbnail_path', 'video_reference', 'duration_seconds', 'unlock_type', 'unlock_at', 'unlock_delay_hours', 'sort_order', 'is_published', 'approval_status', 'submitted_at', 'approved_by', 'approved_at', 'rejection_note'];

    protected function casts(): array
    {
        return ['unlock_at' => 'datetime', 'is_published' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function module(): BelongsTo { return $this->belongsTo(BatchModule::class, 'batch_module_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
