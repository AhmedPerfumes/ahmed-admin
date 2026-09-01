<?php

namespace Ahmed\CompleteOrderProducts\Tables;

use Ahmed\CompleteOrderProducts\Models\CompleteOrderProduct;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Actions\EditAction;
use Botble\Table\BulkActions\DeleteBulkAction;
use Botble\Table\BulkChanges\CreatedAtBulkChange;
use Botble\Table\BulkChanges\StatusBulkChange;
use Botble\Table\Columns\CreatedAtColumn;
use Botble\Table\Columns\IdColumn;
use Botble\Table\Columns\StatusColumn;
use Botble\Table\Columns\ImageColumn;
use Botble\Table\Columns\Column;
use Botble\Table\HeaderActions\CreateHeaderAction;
use Illuminate\Database\Eloquent\Builder;

class CompleteOrderProductTable extends TableAbstract
{
    public function setup(): void
    {
        $this
            ->model(CompleteOrderProduct::class)
            ->addHeaderAction(CreateHeaderAction::make()->route('complete-order-product.create'))
            ->addActions([
                EditAction::make()->route('complete-order-product.edit'),
                DeleteAction::make()->route('complete-order-product.destroy'),
            ])
            ->addColumns([
                IdColumn::make(),
                ImageColumn::make('product.image')
                    ->title('Image')
                    ->alignStart()
                    ->orderable(false)
                    ->searchable(false),
                Column::make('product_name')
                    ->title('Product Name')
                    ->alignStart()
                    ->getValueUsing(function (CompleteOrderProduct $item) {
                        return $item->product ? $item->product->name : ('Product ID #' . $item->product_id);
                    }),
                Column::make('custom_title')
                    ->title('Custom Title')
                    ->alignStart(),
                Column::make('order_index')
                    ->title('Display Order')
                    ->alignCenter(),
                CreatedAtColumn::make(),
                StatusColumn::make(),
            ])
            ->addBulkActions([
                DeleteBulkAction::make()->permission('complete-order-product.destroy'),
            ])
            ->addBulkChanges([
                StatusBulkChange::make(),
                CreatedAtBulkChange::make(),
            ])
            ->queryUsing(function (Builder $query) {
                $query->with(['product'])->select([
                    'id',
                    'product_id',
                    'custom_title',
                    'order_index',
                    'created_at',
                    'status',
                ]);
            });
    }
}
