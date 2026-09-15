<?php

namespace Botble\SimpleSlider\Forms;

use Botble\Base\Forms\FieldOptions\DescriptionFieldOption;
use Botble\Base\Forms\FieldOptions\MediaImageFieldOption;
use Botble\Base\Forms\FieldOptions\SortOrderFieldOption;
use Botble\Base\Forms\Fields\MediaImageField;
use Botble\Base\Forms\Fields\NumberField;
use Botble\Base\Forms\Fields\TextareaField;
use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\SimpleSlider\Http\Requests\SimpleSliderItemRequest;
use Botble\SimpleSlider\Models\SimpleSliderItem;
use Botble\Base\Forms\Fields\SelectField;
use Botble\Base\Forms\FieldOptions\SelectFieldOption;

class SimpleSliderItemForm extends FormAbstract
{
    public function setup(): void
    {
        $this
            ->model(SimpleSliderItem::class)
            ->setValidatorClass(SimpleSliderItemRequest::class)
            ->contentOnly()
            ->add('simple_slider_id', 'hidden', [
                'value' => $this->getRequest()->input('simple_slider_id'),
            ])
            ->add('rowOpen1', 'html', ['html' => '<div class="row">'])
            ->add('title', TextField::class, [
                'label' => trans('core/base::forms.title') . ' (EN)',
                'attr' => [
                    'data-counter' => 120,
                ],
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('title_ar', TextField::class, [
                'label' => trans('core/base::forms.title') . ' (AR)',
                'attr' => [
                    'data-counter' => 120,
                ],
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('rowClose1', 'html', ['html' => '</div>'])
            ->add('rowOpen2', 'html', ['html' => '<div class="row">'])
            ->add('sub_title', TextField::class, [
                'label' => 'Sub Title (EN)',
                'attr' => [
                    'data-counter' => 120,
                ],
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('sub_title_ar', TextField::class, [
                'label' => 'Sub Title (AR)',
                'attr' => [
                    'data-counter' => 120,
                ],
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('rowClose2', 'html', ['html' => '</div>'])
            ->add('season', TextField::class, [
                'label' => 'Season (EN)',
                'attr' => [
                    'data-counter' => 120,
                ],
            ])
            ->add('season_ar', TextField::class, [
                'label' => 'Season (AR)',
                'attr' => [
                    'data-counter' => 120,
                ],
            ])
            ->add('link', TextField::class, [
                'label' => trans('core/base::forms.link'),
                'attr' => [
                    'data-counter' => 120,
                ],
            ])
            ->add('rowOpen4', 'html', ['html' => '<div class="row">'])
            ->add('color', 'customColor', [
                'label' => trans('plugins/ecommerce::product-label.color'),
                'attr' => [
                    'placeholder' => trans('plugins/ecommerce::product-label.color_placeholder'),
                ],
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('order', NumberField::class, [
                'label' => trans('core/base::forms.order'),
                'attr' => [
                    'placeholder' => trans('core/base::forms.order_placeholder'),
                ],
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('rowClose4', 'html', ['html' => '</div>'])
            ->add('rowOpen5', 'html', ['html' => '<div class="row">'])
            ->add('image', MediaImageField::class, [
                'label' => 'Desktop Image',
                'required' => true,
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('mobile_image', MediaImageField::class, [
                'label' => 'Mobile Image',
                'required' => false,
                'wrapper' => [
                    'class' => 'form-group col-md-6',
                ],
            ])
            ->add('rowClose5', 'html', ['html' => '</div>']);
    }
}
