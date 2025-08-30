@extends($layout ?? BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h4">Promotions List</h1>
                <div>
                    <a href="{{ route('promotions.create') }}" class="btn btn-success">Create Promotion</a>
                    <button type="submit" form="bulkDeleteForm" class="btn btn-danger ms-2" id="bulkDeleteBtn" disabled>
                        Delete Selected
                    </button>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    @if($promotions->isEmpty())
                        <p class="text-muted">No promotions found.</p>
                    @else
                        <form id="bulkDeleteForm" action="{{ route('promotions.bulkDelete') }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <table id="promotionsTable" class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="selectAll">
                                        </th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Details</th>
                                        <th>Actions</th>
                                        <th>Status</th>

                                    </tr>
                                </thead>
                                <tbody>
                                    
                                   @foreach($promotions as $promotion)
    @php
        $isExpired = $promotion->end_date ? \Carbon\Carbon::parse($promotion->end_date)->isPast() : false;
        $rowClass = $isExpired ? 'bg-gray text-muted' : '';
        $strikeStyle = $isExpired ? 'text-decoration: line-through;' : '';
    @endphp
    <tr class="{{ $rowClass }}" style="{{ $strikeStyle }}">
        <td>
            <input type="checkbox" name="ids[]" value="{{ $promotion->id }}" class="selectItem">
        </td>
        <td>{{ $promotion->name ?? 'N/A' }}</td>
        <td>{{ ucfirst($promotion->type ?? 'N/A') }}</td>
        <td>{{ $promotion->start_date ? \Carbon\Carbon::parse($promotion->start_date)->format('Y-m-d') : 'N/A' }}</td>
        <td>{{ $promotion->end_date ? \Carbon\Carbon::parse($promotion->end_date)->format('Y-m-d') : 'N/A' }}</td>
        <td>
            @php $type = $promotion->type ?? ''; @endphp
            @if($type === 'bogo')
                <strong>BOGO Rules:</strong>
                <ul>
                    @foreach(data_get($promotion, 'conditions.bogo.product_ids', []) as $index => $buyProductId)
                        @php
                            $freeProductId = data_get($promotion, "rewards.bogo.free_product_ids.$index");
                            $buyProduct = $products->firstWhere('id', $buyProductId);
                            $freeProduct = $products->firstWhere('id', $freeProductId);
                        @endphp
                        <li>Buy: {{ $buyProduct->name ?? $buyProductId }}, Free: {{ $freeProduct->name ?? $freeProductId }}</li>
                    @endforeach
                </ul>
            @elseif($type === 'buy_x_get_y')
                <strong>Buy X Get Y:</strong>
                <p>Buy Quantity: {{ data_get($promotion, 'conditions.buy_x_get_y.buy_quantity', 'N/A') }}</p>
                <p>Get Quantity: {{ data_get($promotion, 'rewards.buy_x_get_y.get_quantity', 'N/A') }}</p>
            @elseif($type === 'discount')
                <strong>Discount:</strong>
                <p>Apply To: {{ data_get($promotion, 'conditions.discount.apply_to', 'N/A') }}</p>
            @elseif($type === 'coupon')
                <strong>Coupon:</strong>
                <p>Code: {{ $promotion->coupon_code ?? 'N/A' }}</p>
            @elseif($type === 'foc')
                <strong>FOC:</strong>
                <p>Min: {{ data_get($promotion, 'conditions.foc.min_threshold', 0) }}</p>
                <p>Max: {{ data_get($promotion, 'conditions.foc.max_threshold', 0) }}</p>
            @endif
        </td>
        <td>
            <a href="{{ route('promotions.edit', $promotion) }}" class="btn btn-primary btn-sm mb-1">Edit</a>
            <form action="{{ route('promotions.destroy', $promotion->id) }}" method="POST" class="d-inline-block" onsubmit="return confirm('Are you sure you want to delete this promotion?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm mb-1">Delete</button>
            </form>
        </td>
        <td>
            @if($isExpired)
                <span class="badge bg-red text-white">Expired</span>
            @else
                <span class="badge bg-success text-white">Active</span>
            @endif
        </td>
    </tr>
@endforeach

                                </tbody>
                            </table>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#promotionsTable').DataTable();

        // Select all toggle
        $('#selectAll').on('click', function() {
            $('.selectItem').prop('checked', this.checked);
            toggleBulkDeleteBtn();
        });

        // Enable/disable delete button
        $(document).on('change', '.selectItem', function() {
            toggleBulkDeleteBtn();
        });

        function toggleBulkDeleteBtn() {
            let anyChecked = $('.selectItem:checked').length > 0;
            $('#bulkDeleteBtn').prop('disabled', !anyChecked);
        }

        // Confirm before bulk delete
        $('#bulkDeleteForm').on('submit', function(e) {
            let count = $('.selectItem:checked').length;
            if (count === 0) {
                e.preventDefault();
                return;
            }
            if (!confirm('Are you sure you want to delete ' + count + ' promotion(s)?')) {
                e.preventDefault();
            }
        });
    });
</script>
@endsection
