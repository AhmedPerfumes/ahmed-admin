<?php

namespace Ahmed\NewProductSlider\Tables;

use Ahmed\NewProductSlider\Models\Newproductslider;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\CreatedAtBulkChange;
use Botble\Table\BulkChanges\NameBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\NameColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\Columns\ImageColumn;
use Botble\Table\Columns\Column;
use Botble\Table\HeaderActions\CreateHeaderAction;
use Illuminate\Database\Eloquent\Builder;

class NewproductsliderTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(Newproductslider::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('new-product-slider.create'))
            ->addActions([
                EditAction::make()->route('new-product-slider.edit'),
                DeleteAction::make()->route('new-product-slider.destroy'),
            ])
            ->addColumns([
                IdColumn::make(),
                ImageColumn::make('product_img')->title('Image'),
                NameColumn::make()->route('new-product-slider.edit'),
                Column::make('category')->title('Category'),
                Column::make('order_index')->title('Order'),
                CreatedAtColumn::make(),
                StatusColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('new-product-slider.destroy'),
            ])
            ->addBulkChanges([
                NameBulkChange::make(),
                StatusBulkChange::make(),
                CreatedAtBulkChange::make(),
            ])
            ->queryUsing(function (Builder $query) {
                $query->select([
                    'id',
                    'product_img',
                    'name',
                    'category',
                    'order_index',
                    'created_at',
                    'status',
                ]);
            });
    }
}
