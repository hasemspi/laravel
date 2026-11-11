<?php

namespace App\Models;

use Database\Factories\BlogWidgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogWidget extends Model
{
    /** @use HasFactory<BlogWidgetFactory> */
    use HasFactory;

    protected $fillable = ['key', 'title', 'body', 'type', 'is_enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
