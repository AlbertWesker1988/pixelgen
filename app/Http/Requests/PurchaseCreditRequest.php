<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditRequest extends FormRequest
{
    /** Fixed top-up packs; a fake purchase can only add one of these. */
    public const array PACKS = ['5', '20', '50'];

    /** Route is already behind auth middleware; any signed-in user may buy. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', Rule::in(self::PACKS)],
        ];
    }
}
