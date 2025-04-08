@extends($layout ?? BaseHelper::getAdminMasterLayoutTemplate())

@section('content')
    <h1>Welcome to the Dynamic Section</h1>
    <p>This is the content of the Dynamic Section page.</p>
    <h3>Submitted Entries</h3>
    <table class="table table-bordered mt-4">
        <thead>
            <tr>
                <th>Heading</th>
                <th>Description</th>
                <th>Image</th>
                <th>Video (Desktop)</th>
                <th>Video (Mobile)</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        @foreach($sections as $section)
            <tr>
                <td>{{ $section->heading }}</td>
                <td>{{ $section->description }}</td>
                <td>
                    @if($section->image)
                        <img src="{{ $section->image }}" alt="Image" width="100">
                    @endif
                </td>
                <td>
                    <video src="{{ $section->video1 }}" width="100" controls></video>
                </td>
                <td>
                    <video src="{{ $section->video2 }}" width="100" controls></video>
                </td>
                <td>
                    <form method="POST" action="{{ route('dynamic-section.destroy', $section->id) }}"onsubmit="return confirm('Are you sure you want to delete this entry?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="newsletter-section">
    <h2>Newsletter Form</h2>
    @if(session('success'))
        <p style="color: green;">{{ session('success') }}</p>
    @endif
    <form action="{{ route('newsletter.submit') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="form-group">
            <label for="heading">Heading</label>
            <input type="text" name="heading" id="heading" class="form-control" placeholder="Enter heading" required>
        </div>
        <div class="form-group">
            <label for="description">Description</label>
            <textarea name="description" id="description" class="form-control" placeholder="Enter description" rows="4" required></textarea>
        </div>
        <div class="form-group">
    <label for="link">Link</label>
    <input type="text" name="link" id="link" class="form-control" placeholder="Enter a valid URL" required>
</div>

  
        <div class="d-flex gap-3">  

            <div class="form-group">
                <label for="image">Select Image</label>
                <div>
                    {!! Form::mediaImage('image', null, ['class' => 'form-control', 'required' => true]) !!}
                </div>
            </div>
            <div class="form-group">
                <label for="image">Select Image</label>
                <div>
                    {!! Form::mediaImage('image', null, ['class' => 'form-control', 'required' => true]) !!}
                </div>
            </div>
            <div class="form-group">
                <label for="video1">Video for desktop</label>
                <div >
                {!! Form::mediaFile('video1', null, ['type' => 'video', 'class' => 'form-control', 'required' => true]) !!}
                </div>
            </div>
            <div class="form-group">
                <label for="video2">Video for mobile</label>
                <div>
                {!! Form::mediaFile('video2', null, ['type' => 'video', 'class' => 'form-control', 'required' => true]) !!}
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Submit</button>
    </form>
</div>
@endsection
