@extends($layout ?? BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <h1>Welcome to the Dynamic Section</h1>
    <p>This is the content of the Dynamic Section page.</p>

    <div class="section-1">
        <h2>Section 1</h2>
        <form action='{{ route('dynamic.section.submit')}}' method="POST" enctype="multipart/form-data">
            @csrf
            <div class="form-group">
                <label for="input1">Input Text 1</label>
                <input type="text" name="input1" id="input1" class="form-control" placeholder="Enter text for Input 1">
            </div>
            <div class="form-group">
                <label for="input2">Input Text 2</label>
                <input type="text" name="input2" id="input2" class="form-control" placeholder="Enter text for Input 2">
            </div>
            <div class="form-group">
                <label for="input3">Input Text 3</label>
                <input type="text" name="input3" id="input3" class="form-control" placeholder="Enter text for Input 3">
            </div>
           <div class="form-group">
    <label for="image">Upload Image</label>
    <div>
        <!-- {!! Form::mediaImage('image', null, ['class' => 'form-control', 'required' => true]) !!} -->
        <input type="file" name="image" id="image" class="form-control" required>
    </div>
</div>

            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </div>
@endsection