<?php

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketStatusRequest extends FormRequest
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
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
            // CRÍTICO (CU-T02): toda transición exige justificación escrita.
            'justification' => ['required', 'string', 'min:10', 'max:2000'],
            'assignedToUserId' => ['nullable', 'integer', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'min:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'justification.required' => 'Debe indicar una justificación para el cambio de estado.',
        ];
    }
}
