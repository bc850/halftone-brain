<?php

namespace App\Http\Requests;

use App\Models\OrganizationProductSource;
use Illuminate\Foundation\Http\FormRequest;

class ActivateOrganizationProductSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var OrganizationProductSource $source */
        $source = $this->route('organizationProductSource');

        return $this->user()?->can('activate', $source) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
