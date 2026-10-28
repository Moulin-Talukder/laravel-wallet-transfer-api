<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransferRequest extends FormRequest
{
    /**
     * Runs before validation, so a frozen sender gets 403 regardless of the payload.
     */
    public function authorize(): bool
    {
        $wallet = $this->user()->wallet;

        return $wallet !== null && $this->user()->can('transferFrom', $wallet);
    }

    /**
     * The header is the only source of the key; any idempotency_key in the body is overwritten.
     */
    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        $this->merge([
            'idempotency_key' => is_string($key) ? strtolower(trim($key)) : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'receiver_email' => [
                'required',
                'string',
                'email',
                Rule::exists('users', 'email'),
                Rule::notIn([$this->user()->email]),
            ],
            // Paisa: 1 BDT minimum, 50,000 BDT maximum per transfer.
            'amount' => ['required', 'integer', 'min:100', 'max:5000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.uuid' => 'The Idempotency-Key header must be a UUID.',
            'receiver_email.not_in' => 'You cannot transfer money to yourself.',
        ];
    }
}
