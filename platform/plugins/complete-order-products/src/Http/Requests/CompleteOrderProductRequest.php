<?php

namespace Ahmed\CompleteOrderProducts\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class CompleteOrderProductRequest extends Request
{
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:ec_products,id'],
            'custom_title' => ['nullable', 'string', 'max:255'],
            'custom_title_ar' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }
}
