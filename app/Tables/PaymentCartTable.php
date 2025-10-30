<?php

namespace App\Tables;

use App\Models\PaymentCart;
use Botble\Base\Facades\BaseHelper;
use Botble\Table\Abstracts\TableAbstract;
use Botble\Table\Actions\Action;
use Botble\Table\Actions\DeleteAction;
use Botble\Table\Columns\Column;
use Botble\Table\Columns\IdColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PaymentCartTable extends TableAbstract
{
    public function setup(): void
    {
        $this->model(PaymentCart::class);

        $this->addActions([
            Action::make('view')->icon('ti ti-eye')->color('info')->route('payment-carts.show')->label('View Details'),
            DeleteAction::make()->route('payment-carts.destroy'),
        ]);
    }


    public function query(): Builder
    {
        $query = $this->model->select([
            'id',
            'customer_id',
            'status',
            'created_at',
            'updated_at',
        ]);

        return $this->applyScopes($query);
    }

    public function columns(): array
    {
        return [
            IdColumn::make(),
            Column::make('customer_id')
                ->title('Customer ID')
                ->alignStart(),
            Column::make('status')
                ->title('Status'),
            Column::make('created_at')
                ->title('Created At'),
            Column::make('updated_at')
                ->title('Last Updated'),
        ];
    }

    public function ajax(): JsonResponse
    {
        $data = $this->table
            ->eloquent($this->query())
            ->editColumn('created_at', function (PaymentCart $item) {
                return BaseHelper::formatDateTime($item->created_at);
            })
            ->editColumn('updated_at', function (PaymentCart $item) {
                return BaseHelper::formatDateTime($item->updated_at);
            });

        return $this->toJson($data);
    }
}