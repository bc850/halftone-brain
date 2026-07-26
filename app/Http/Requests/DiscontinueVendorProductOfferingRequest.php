<?php

namespace App\Http\Requests;

use App\Models\VendorProductOffering;
use Illuminate\Foundation\Http\FormRequest;

class DiscontinueVendorProductOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var VendorProductOffering $offering */
        $offering = $this->route('vendorProductOffering');

        return $this->user()?->can('discontinue', $offering) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
