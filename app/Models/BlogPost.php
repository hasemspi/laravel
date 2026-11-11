<?php

namespace App\Models;

use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'blog_category_id', 'title', 'slug', 'excerpt', 'content', 'image_path',
        'image_alt', 'author_name', 'status', 'is_featured', 'published_at',
        'read_time_minutes', 'views',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'read_time_minutes' => 'integer',
            'views' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'blog_category_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now());
    }
}
