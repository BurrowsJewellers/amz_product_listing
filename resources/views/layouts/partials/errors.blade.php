@if ($errors->any())
    <div class="row mt-4">
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

@if (session()->has('success'))
    <div class="row mt-4">
        <div class="alert alert-success">
            {{ session()->get('success') }}
        </div>
    </div>
@endif
