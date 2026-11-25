<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['video_type', 'video_path', 'youtube_url', 'youtube_video_id', 'updated_by'])]
class WebsiteVideoSetting extends Model
{
    protected $table = 'website_video_settings';

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
