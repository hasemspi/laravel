<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminContactMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $messages = ContactMessage::query()
            ->select(['id', 'name', 'email', 'phone', 'subject', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return response()->json($messages);
    }

    public function show(ContactMessage $contactMessage): JsonResponse
    {
        return response()->json(['contact_message' => $contactMessage]);
    }
}
