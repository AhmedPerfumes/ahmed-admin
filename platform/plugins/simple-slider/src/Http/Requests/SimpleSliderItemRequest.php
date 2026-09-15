<?php

namespace Botble\SimpleSlider\Http\Requests;

use Botble\Support\Http\Requests\Request;

class SimpleSliderItemRequest extends Request
{
    public function rules(): array
    {
        return [
            'simple_slider_id' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'sub_title' => ['nullable', 'string', 'max:255'],
            'sub_title_ar' => ['nullable', 'string', 'max:255'],
            'season' => ['nullable', 'string', 'max:255'],
            'season_ar' => ['nullable', 'string', 'max:255'],
            'image' => ['required', 'string'],
            'mobile_image' => ['nullable', 'string'],
            'order' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
