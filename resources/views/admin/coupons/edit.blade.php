@extends('layouts.admin')

@section('content')
    @include('admin.coupons._form', [
        'coupon' => $coupon,
        'plans' => $plans,
        'durationTypes' => $durationTypes,
        'isEdit' => true,
        'formAction' => route('admin.coupons.update', $coupon),
    ])
@endsection
