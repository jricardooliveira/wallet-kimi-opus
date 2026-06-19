<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DebitRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the data to be validated from the request.
     *
     * @return array
     */
    public function validationData()
    {
        return array_merge($this->all(), [
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function (Validator $validator) {
            $amount = $this->input('amount');

            if (!is_string($amount)) {
                return;
            }

            if (!preg_match('/^(0|[1-9]\d*)(\.\d{1,4})?$/', $amount)) {
                $validator->errors()->add('amount', 'The amount must be a positive decimal with up to 4 fractional digits.');
                return;
            }

            if (bccomp($amount, '0', 4) !== 1) {
                $validator->errors()->add('amount', 'The amount must be greater than zero.');
            }
        });
    }
}
