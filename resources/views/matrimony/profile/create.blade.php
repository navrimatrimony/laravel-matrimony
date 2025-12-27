<h1>Matrimony Profile Create</h1>

<form method="POST" action="{{ route('matrimony.profile.store') }}">
    @csrf

    <label>Full Name</label><br>
    <input type="text" name="full_name"><br><br>

    <label>Date of Birth</label><br>
    <input type="date" name="date_of_birth"><br><br>

    <label>Education</label><br>
    <input type="text" name="education"><br><br>

    <label>Location</label><br>
    <input type="text" name="location"><br><br>

    <button type="submit">Save Profile</button>
</form>
