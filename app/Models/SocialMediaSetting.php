<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'facebook_url',
    'youtube_url',
    'linkedin_url',
    'instagram_url',
    'whatsapp_url',
    'updated_by',
])]
class SocialMediaSetting extends Model
{
    protected $table = 'social_media_settings';

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
