@extends('layouts.admin')

@use('Illuminate\Support\Str')

@section('content')
<h1>Payment disputes</h1>
<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>User</th>
            <th>Reason</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($disputes as $d)
            <tr>
                <td>
                    @if ($d->user)
                        #{{ $d->user->id }} {{ $d->user->name }} ({{ $d->user->email }})
                    @else
                        —
                    @endif
                </td>
                <td>{{ Str::limit($d->reason, 200) }}</td>
                <td>{{ $d->status }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3">No disputes.</td>
            </tr>
        @endforelse
    </tbody>
</table>
<p>{{ $disputes->links() }}</p>
@endsection
