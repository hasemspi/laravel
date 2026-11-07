<?php

namespace App\Http\Requests;

use App\Models\Course;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends StoreCourseRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $course = $this->route('course');
        $courseId = $course instanceof Course ? $course->id : $course;
        $rules['slug'] = ['sometimes', 'required', 'string', 'max:200', 'alpha_dash', Rule::unique('courses', 'slug')->ignore($courseId)];
        foreach (['course_category_id', 'title', 'short_description', 'description', 'price', 'discount_price', 'level', 'duration_minutes', 'thumbnail_path', 'benefits', 'status', 'is_featured'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        return $rules;
    }
}
