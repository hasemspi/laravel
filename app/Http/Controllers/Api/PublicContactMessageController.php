<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicContactMessageController extends Controller
{
    private const SUBJECTS = [
        'General Inquiry',
        'Course Admission',
        'Web Development Course',
        'Digital Marketing Course',
        'Graphics Design Course',
        'AI & Technology Course',
        'Freelancing Guidance',
        'Technical Support',
        'Other',
    ];

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'email' => is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : $request->input('email'),
            'phone' => is_string($request->input('phone')) ? trim($request->input('phone')) : $request->input('phone'),
            'subject' => is_string($request->input('subject')) ? trim($request->input('subject')) : $request->input('subject'),
            'message' => is_string($request->input('message')) ? trim(strip_tags($request->input('message'))) : $request->input('message'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+().\s-]{7,40}$/'],
            'subject' => ['required', 'string', Rule::in(self::SUBJECTS)],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $contactMessage = ContactMessage::query()->create($validated);

        return response()->json([
            'message' => 'Your message has been received. We will get back to you shortly.',
            'contact_message' => ['id' => $contactMessage->id],
        ], 201);
    }
}
