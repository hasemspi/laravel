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
        foreach (['course_category_id', 'title', 'short_description', 'price', 'status'] as $field) $rules[$field][0] = 'sometimes';
        return $rules;
    }
}
