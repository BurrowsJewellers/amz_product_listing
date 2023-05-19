@extends('layouts.app')

@section('content')
<div class="card">
    <div class="card-body align-items-center">
        <div class="row">
            <div class="col-sm-6 col-lg-5">
                <form action="{{ route('user-profile-information.update')}}" method="post">
                    @method('PUT')
                    @csrf
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="{{ auth()->user()->name }}" placeholder="Enter your name">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email address</label>
                        <input type="email" class="form-control" id="email" name="email" value="{{ auth()->user()->email }}" placeholder="Enter your email address">
                    </div>
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
            @include('layouts.partials.errors')
        </div>
    </div>
</div>

@endsection