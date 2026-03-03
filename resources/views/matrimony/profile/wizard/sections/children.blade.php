<h3 class="text-base font-semibold mb-3">Children Details</h3>

<div id="children-container" class="space-y-4">
    <div class="child-row border rounded p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            <div>
                <label class="block text-sm mb-1">Child Name</label>
                <input type="text" name="children[0][child_name]" class="w-full rounded border px-3 py-2">
            </div>

            <div>
                <label class="block text-sm mb-1">Gender</label>
                <select name="children[0][gender]" class="w-full rounded border px-3 py-2">
                    <option value="">Select</option>
                    <option value="male">Boy</option>
                    <option value="female">Girl</option>
                </select>
            </div>

            <div>
                <label class="block text-sm mb-1">Age</label>
                <input type="number" name="children[0][age]" class="w-full rounded border px-3 py-2">
            </div>

            <div>
                <label class="block text-sm mb-1">Living With</label>
                <select name="children[0][child_living_with_id]" class="w-full rounded border px-3 py-2">
                    <option value="">Select</option>
                    @foreach(\App\Models\MasterChildLivingWith::all() as $opt)
                        <option value="{{ $opt->id }}">{{ $opt->label }}</option>
                    @endforeach
                </select>
            </div>

        </div>
    </div>
</div>

<button type="button" id="add-child-btn" class="mt-3 px-4 py-2 bg-gray-200 rounded">
    + Add Child
</button>