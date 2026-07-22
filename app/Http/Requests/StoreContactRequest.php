<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Contact::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'company_id' => [
                'required',
                'integer',
                Rule::exists('companies', 'id')->where(function ($query) use ($user): void {
                    if (! $user->canSeeEveryone()) {
                        $query->where('owner_id', $user->id);
                    }
                }),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:150'],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function company(): Company
    {
        return Company::query()->findOrFail($this->integer('company_id'));
    }
}
