<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => 'required|in:pos,mobile,backend',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'till_id' => 'nullable|integer|exists:tills,id',
            'route_id' => 'nullable|integer|exists:routes,id',
        ];
    }
}
