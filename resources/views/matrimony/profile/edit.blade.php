<h1>Matrimony Profile Edit</h1>
@if (session('success'))
    <p style="color: green;">
        {{ session('success') }}
    </p>
@endif

<form method="POST" action="{{ route('matrimony.profile.update') }}">
    @csrf

    <label>Full Name</label><br>
    <input type="text" name="full_name" value="{{ $profile->full_name }}"><br><br>

    <label>Date of Birth</label><br>
    <input type="date" name="date_of_birth" value="{{ $profile->date_of_birth }}"><br><br>

    <label>Education</label><br>
    <input type="text" name="education" value="{{ $profile->education }}"><br><br>

    <label>Location</label><br>
    <input type="text" name="location" value="{{ $profile->location }}"><br><br>

    <button type="submit">Update Profile</button>
</form>
