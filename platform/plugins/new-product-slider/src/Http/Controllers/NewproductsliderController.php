<?php

namespace Ahmed\NewProductSlider\Http\Controllers;

use Botble\Base\Http\Actions\DeleteResourceAction;
use Ahmed\NewProductSlider\Http\Requests\NewproductsliderRequest;
use Ahmed\NewProductSlider\Models\Newproductslider;
use Botble\Base\Http\Controllers\BaseController;
use Ahmed\NewProductSlider\Tables\NewproductsliderTable;
use Ahmed\NewProductSlider\Forms\NewproductsliderForm;

class NewproductsliderController extends BaseController
{
    public function __construct()
    {
        $this
            ->breadcrumb()
            ->add(trans(trans('plugins/new-product-slider::newproductslider.name')), route('new-product-slider.index'));
    }

    public function index(NewproductsliderTable $table)
    {
        $this->pageTitle(trans('plugins/new-product-slider::newproductslider.name'));

        return $table->renderTable();
    }

    public function create()
    {
        $this->pageTitle(trans('plugins/new-product-slider::newproductslider.create'));

        return NewproductsliderForm::create()->renderForm();
    }

    public function store(NewproductsliderRequest $request)
    {
        $form = NewproductsliderForm::create()->setRequest($request);

        $form->save();

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('new-product-slider.index'))
            ->setNextUrl(route('new-product-slider.edit', $form->getModel()->getKey()))
            ->setMessage(trans('core/base::notices.create_success_message'));
    }

    public function edit(Newproductslider $new_product_slider)
    {
        $this->pageTitle(trans('core/base::forms.edit_item', ['name' => $new_product_slider->name]));

        return NewproductsliderForm::createFromModel($new_product_slider)->renderForm();
    }

    public function update(Newproductslider $new_product_slider, NewproductsliderRequest $request)
    {
        NewproductsliderForm::createFromModel($new_product_slider)
            ->setRequest($request)
            ->save();

        return $this
            ->httpResponse()
            ->setPreviousUrl(route('new-product-slider.index'))
            ->setMessage(trans('core/base::notices.update_success_message'));
    }

    public function destroy(Newproductslider $new_product_slider)
    {
        return DeleteResourceAction::make($new_product_slider);
    }
}
