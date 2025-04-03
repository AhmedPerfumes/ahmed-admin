@extends($layout ?? BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <h1>Welcome to the Dynamic Section</h1>
    <p>This is the content of the Dynamic Section page.</p>

    <div class="section-1">
        <h2>Section 1</h2>
        @if(session('success'))
            <p style="color: green;">{{ session('success') }}</p>
        @endif
        <form action='{{ route('dynamic.section.submit')}}' method="POST" enctype="multipart/form-data">
            @csrf
            <div class="form-group">
                <label for="device_type_2">Select Device Type</label>
                <select name="device_type_2" id="device_type_2" class="form-control">
                    <option value="mobile">Mobile</option>
                    <option value="desktop">Desktop</option>
                </select>
            </div>
            <div class="form-group">
                <label for="section1_title">Title</label>
                <input type="text" name="section1_title" id="section1_title" class="form-control" placeholder="Enter title for section 1">
            </div>
           
            <div class="form-group">
                <label for="section1_subtitle">Subtitle</label>
                <input type="text" name="section1_subtitle" id="section1_subtitle" class="form-control" placeholder="Enter text for Input 2">
            </div>
           
            <div class="form-group">
                <label for="section1_description">Description</label>
                <input type="text" name="section1_description" id="section1_description" class="form-control" placeholder="Enter text for Input 3">
            </div>
            <div class="form-group">
                <label for="image">Upload Image</label>
                <div>
                    {!! Form::mediaImage('image', null, ['class' => 'form-control', 'required' => true]) !!}
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </div>
@endsection
