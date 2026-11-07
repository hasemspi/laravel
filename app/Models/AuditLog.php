<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'event', 'auditable_type', 'auditable_id', 'metadata', 'ip_address'];
    protected function casts(): array { return ['metadata' => 'array']; }
}
