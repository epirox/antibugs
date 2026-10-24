<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'email' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'email',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'min:8'],
            'role' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::enum(Role::class)],
        ];
    }
}
