@extends('layouts.admin')

@section('content')
<h1>Payments</h1>
<form method="get" action="{{ route('admin.payments.index') }}">
    <label for="txnid">Txn ID</label>
    <input type="text" id="txnid" name="txnid" value="{{ request('txnid') }}">
    <button type="submit">Search</button>
</form>
<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Txn ID</th>
            <th>User</th>
            <th>Amount</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($payments as $p)
            <tr>
                <td>{{ $p->txnid }}</td>
                <td>
                    @if ($p->user)
                        #{{ $p->user->id }} {{ $p->user->name }} ({{ $p->user->email }})
                    @else
                        —
                    @endif
                </td>
                <td>{{ $p->amount }}</td>
                <td>{{ $p->payment_status ?? $p->status }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4">No payments.</td>
            </tr>
        @endforelse
    </tbody>
</table>
<p>{{ $payments->links() }}</p>
@endsection
