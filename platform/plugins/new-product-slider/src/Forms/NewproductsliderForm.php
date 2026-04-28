<?php

namespace Ahmed\NewProductSlider\Forms;

use Botble\Base\Forms\FieldOptions\NameFieldOption;
use Botble\Base\Forms\FieldOptions\StatusFieldOption;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Ahmed\NewProductSlider\Http\Requests\NewproductsliderRequest;
use Ahmed\NewProductSlider\Models\Newproductslider;

class NewproductsliderForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->model(Newproductslider::class)
            ->setValidatorClass(NewproductsliderRequest::class)
            ->add('product_id', \Botble\Base\Forms\Fields\SelectField::class, [
                'label' => 'Linked Product (for Add to Cart)',
                'choices' => [0 => '-- None --'] + \Botble\Ecommerce\Models\Product::query()->where('is_variation', 0)->pluck('name', 'id')->toArray(),
                'attr' => ['class' => 'select-search-full'],
            ])
            ->add('name', \Botble\Base\Forms\Fields\TextField::class, NameFieldOption::make()->label('Name (English)')->required()->toArray())
            ->add('name_ar', \Botble\Base\Forms\Fields\TextField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Name (Arabic)')->placeholder('e.g. زمرد')->toArray())
            ->add('category', \Botble\Base\Forms\Fields\TextField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Category (English)')->placeholder('e.g. Oriental Fragrance')->toArray())
            ->add('category_ar', \Botble\Base\Forms\Fields\TextField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Category (Arabic)')->placeholder('e.g. عطر شرقي')->toArray())
            ->add('desc', \Botble\Base\Forms\Fields\TextareaField::class, \Botble\Base\Forms\FieldOptions\TextareaFieldOption::make()->label('Description (English)')->placeholder('e.g. Crafted for those who embrace elegance')->toArray())
            ->add('desc_ar', \Botble\Base\Forms\Fields\TextareaField::class, \Botble\Base\Forms\FieldOptions\TextareaFieldOption::make()->label('Description (Arabic)')->placeholder('e.g. مصمم لمن يقدرون الأناقة')->toArray())
            ->add('product_img', \Botble\Base\Forms\Fields\MediaImageField::class, \Botble\Base\Forms\FieldOptions\MediaImageFieldOption::make()->label('Primary Product Photo')->toArray())
            ->add('note_img', \Botble\Base\Forms\Fields\MediaImageField::class, \Botble\Base\Forms\FieldOptions\MediaImageFieldOption::make()->label('Secondary / Notes Photo')->toArray())
            ->add('theme_bg', \Botble\Base\Forms\Fields\ColorField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Theme Background Color')->placeholder('#FDFBF7')->toArray())
            ->add('theme_accent', \Botble\Base\Forms\Fields\ColorField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Accent Color')->placeholder('#DDC5A2')->toArray())
            ->add('theme_glow', \Botble\Base\Forms\Fields\ColorField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Glow Color')->placeholder('rgba(221,197,162,0.15)')->toArray())
            ->add('link', \Botble\Base\Forms\Fields\TextField::class, \Botble\Base\Forms\FieldOptions\TextFieldOption::make()->label('Discover Link URL')->placeholder('e.g. /en/shop/perfumes/oriental-fragrance/zumar')->toArray())
            ->add('order_index', \Botble\Base\Forms\Fields\NumberField::class, \Botble\Base\Forms\FieldOptions\NumberFieldOption::make()->label('Display Order Index')->defaultValue(0)->toArray())
            ->add('status', SelectField::class, StatusFieldOption::make()->toArray())
            ->setBreakFieldPoint('status');
    }
}
