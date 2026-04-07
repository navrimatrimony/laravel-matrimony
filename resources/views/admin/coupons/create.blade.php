@extends('layouts.admin')

@section('content')
    @include('admin.coupons._form', [
        'coupon' => $coupon,
        'plans' => $plans,
        'durationTypes' => $durationTypes,
        'isEdit' => false,
        'formAction' => route('admin.coupons.store'),
    ])
@endsection
