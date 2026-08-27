<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOrderRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'products' => 'required|array',
            'finalPrice' => 'required|numeric|gt:0',
            'totalPrice' => 'required|numeric|gt:0'
        ];

        if (!$this->input('customer_id')) {
            $rules = array_merge($rules, [
                'billingAddress.first_name' => 'required|string|max:255',
                'billingAddress.last_name'  => 'required|string|max:255',
                'billingAddress.email'      => 'required|string|max:255',
                'billingAddress.mobile'     => 'required|numeric',
                'billingAddress.area'       => 'required|string',
                'billingAddress.building'   => 'required|string',
                'billingAddress.emirates'   => 'required|string',
            ]);
        }

        return $rules;
    }

    /**
     * Handle a failed validation attempt for API.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
