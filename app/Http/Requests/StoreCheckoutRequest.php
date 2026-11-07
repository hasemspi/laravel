<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'mobile' => ['required', 'string', 'max:30'],
            'transaction_id' => ['required', 'string', 'min:6', 'max:100', 'unique:payments,transaction_id'],
            'password' => ['nullable', 'string', 'min:8', 'max:72', 'confirmed'],
        ];
    }
}
