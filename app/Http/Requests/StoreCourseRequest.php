<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin' && $this->user()?->status === 'active';
    }

    public function rules(): array
    {
        return [
            'course_category_id' => ['nullable', 'integer', 'exists:course_categories,id'],
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['required', 'string', 'max:200', 'alpha_dash', 'unique:courses,slug'],
            'short_description' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:50000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'lte:price'],
            'level' => ['required', Rule::in(['beginner', 'intermediate', 'advanced', 'all_levels'])],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'thumbnail_path' => ['nullable', 'string', 'max:2048'],
            'benefits' => ['nullable', 'array', 'max:30'],
            'benefits.*' => ['string', 'max:300'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'is_featured' => ['sometimes', 'boolean'],
            'display_student_count' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'display_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'display_review_count' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'trainer_ids' => ['sometimes', 'array'],
            'trainer_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'trainer')->where('status', 'active'))],
        ];
    }
}
