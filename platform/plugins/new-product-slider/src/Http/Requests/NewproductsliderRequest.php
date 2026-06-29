<?php

namespace Ahmed\NewProductSlider\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class NewproductsliderRequest extends Request
{
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:220'],
            'name_ar' => ['nullable', 'string', 'max:220'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_ar' => ['nullable', 'string', 'max:255'],
            'desc' => ['nullable', 'string'],
            'desc_ar' => ['nullable', 'string'],
            'product_img' => ['nullable', 'string'],
            'note_img' => ['nullable', 'string'],
            'theme_bg' => ['nullable', 'string', 'max:50'],
            'theme_accent' => ['nullable', 'string', 'max:50'],
            'theme_glow' => ['nullable', 'string', 'max:50'],
            'link' => ['nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer'],
            'status' => Rule::in(BaseStatusEnum::values()),
        ];
    }
}
