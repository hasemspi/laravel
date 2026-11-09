<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseBatch extends Model
{
    protected $fillable = ['course_id', 'batch_number', 'release_date', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['release_date' => 'datetime'];
    }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function trainers(): BelongsToMany { return $this->belongsToMany(User::class, 'batch_trainers')->withTimestamps(); }
    public function modules(): HasMany { return $this->hasMany(BatchModule::class); }
    public function accessGrants(): HasMany { return $this->hasMany(EnrollmentBatchAccess::class); }
}
