<?php

namespace App\Http\Requests;

use App\Enums\DealStage;
use App\Http\Requests\Concerns\NormalizesDealMoney;
use App\Models\User;
use App\Rules\AssignableOwner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDealRequest extends FormRequest
{
    use NormalizesDealMoney;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('deal')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => [
                'required',
                'integer',
                Rule::exists('companies', 'id')->where(function ($query) use ($user): void {
                    if (! $user->canSeeEveryone()) {
                        $query->where('owner_id', $user->id);
                    }
                }),
            ],
            'primary_contact_id' => [
                'nullable',
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('company_id', $this->integer('company_id'))),
            ],
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => [
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('company_id', $this->integer('company_id'))),
            ],
            'owner_id' => [
                Rule::excludeIf(fn (): bool => ! $user->isAdmin()),
                'nullable',
                'integer',
                AssignableOwner::rule(),
            ],
            'stage' => ['required', Rule::enum(DealStage::class)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        return $this->normalizeDealMoney($validated);
    }
}
