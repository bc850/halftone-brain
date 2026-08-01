<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AbandonIntegrationOutboxDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delivery = $this->route('outboxDelivery');

        return $delivery !== null && ($this->user()?->can('abandon', $delivery) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expected_status' => ['required', 'string', 'max:64'],
            'confirm' => ['accepted'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function expectedStatus(): string
    {
        return (string) $this->validated('expected_status');
    }
}
