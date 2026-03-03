# Profile Wizard — Structural Layout Diagnosis (Inspection Only, No Fix Applied)

## 1) Exact file causing grid collapse

**File:** `resources/views/matrimony/profile/wizard/sections/basic_info.blade.php`

---

## 2) Exact line where grid structure breaks

**Line 41.** The opening `<div id="marriage-history-container" style="display:none;">` is placed **inside** the grid that starts at **line 28**, as the **second direct child** of that grid.

Structure:

- **Line 28:** `<div class="grid grid-cols-1 md:grid-cols-2 gap-4">` — grid starts.
- **Lines 29–37:** First grid child: Marital Status (wrapped in `<div>` … `</div>`).
- **Lines 40–44:** Second grid child: `#marriage-history-container` (wraps the marriages include).
- **Lines 46–49:** Third grid child: Height (cm).
- **Lines 50–53:** Fourth grid child: Primary contact (`md:col-span-2`).
- **Lines 54–62:** Fifth grid child: Serious intent (`md:col-span-2`).
- **Line 63:** `</div>` — grid closes.

So the marriage container is a **grid item** in a 2‑column grid, between Marital Status and Height. That is the structural fault.

---

## 3) Whether marriages include is inside the wrong grid container

**Yes.** The marriages include is inside the wrong container.

- It is **inside** the `grid grid-cols-1 md:grid-cols-2 gap-4` that starts at line 28.
- It is the **second grid cell** (sibling to Marital Status, Height, etc.).
- Marriage history is a full-width block of content (multiple fields/cards). Putting it in a single grid cell makes the second column very tall and causes the grid to break when the container is shown.
- When the container has `display:none`, it is removed from layout flow, so the grid effectively has: Marital Status | Height | contact | intent. When `display:block`, the grid has: Marital Status | Marriage block | Height | … so Height moves to the next row. So the layout depends on marital status because the marriage block is a grid item.

---

## 4) Why the height field moves when marital status changes

- **never_married (or any status where marriage is hidden):**  
  `#marriage-history-container` has `display:none`, so it does not participate in the grid. Direct grid children are: Marital Status, Height, Primary contact, Serious intent. So row 1 is Marital Status | Height. Height appears beside marital status.

- **divorced / separated / widowed:**  
  JS sets `marriageContainer.style.display = 'block'`. The marriage container is now a grid child. Grid order is: Marital Status → Marriage container → Height → … So row 1 is Marital Status | Marriage container, and Height becomes the third item and appears on the next row (left column). So height “shifts below” when marriage history is visible.

So the height field moves because the **grid column count and item order** change: the marriage container is an extra grid item that only appears for certain marital statuses.

---

## 5) Whether a conditional wrapper is swallowing the children section

- **Blade:** There is no conditional in `basic_info.blade.php` that wraps or hides a “children section”. The only conditional visibility is the **JS** that toggles `#marriage-history-container` and `#children-section` via `style.display`.
- **Children section in DOM:** The script in `basic_info.blade.php` (lines 159–182) does `var childrenSection = document.getElementById('children-section');` and `toggleChildrenSection()`. The current **marriages.blade.php** (after the last rewrite) does **not** output any element with `id="children-section"`. The old marriages blade used to have a block like `<div id="children-section" style="display:none;">` and an `@include('...children')`. That was removed when marriages was replaced. So **`#children-section` does not exist in the DOM** when the basic-info section is rendered. The JS runs but finds `null`, so it does nothing. So the “children section” the script expects is missing from the template, which explains it “disappearing” or never showing.

---

## 6) Div nesting (summary)

- The grid at line 28 is closed at line 63. Between them there are five direct children; each is correctly wrapped in divs. So there is no **unclosed** grid or **extra** `</div>` in that block.
- The structural problem is **placement**: the marriage block is a **child** of the grid instead of a **sibling** (full-width block after the grid). So the fix is to move the marriage block, not to add/remove a closing tag.

---

## 7) Exact minimal structural fix required (description only — not applied)

**Goal:** Keep the same content and behavior, but stop the grid from breaking and stop height from moving when marital status changes.

**Minimal structural change:**

1. **Move the marriage-history block outside the grid.**  
   Do **not** have `#marriage-history-container` (and the marriages include) as a direct child of the grid that contains Marital Status and Height.

2. **Concrete change in `basic_info.blade.php`:**
   - **After** the Marital Status `<div>…</div>` (after line 37), **do not** output the marriage container.
   - **After** the grid’s closing `</div>` (after line 63), **output** the marriage container as a full-width block, e.g.:
     - `<div id="marriage-history-container" style="display:none;">`  
     - `@include('matrimony.profile.wizard.sections.marriages')`  
     - `</div>`
   - So the grid (lines 28–63) should contain **only**: Marital Status, Height, Primary contact, Serious intent. Remove the marriage container from between Marital Status and Height (lines 40–44) and insert it once, **after** the grid (after line 63).

3. **Result:**  
   - Grid always has the same four children → row 1 is always Marital Status | Height; height no longer moves with marital status.  
   - Marriage history appears below the grid when shown by JS; it is no longer a grid cell, so the grid no longer collapses or shifts.

4. **Children section:**  
   If the product requirement is to show a “children” block on basic-info when status is divorced/separated/widowed, then the template must again output an element with `id="children-section"` (e.g. in `basic_info.blade.php` or inside the marriages include) and include the children partial so that `toggleChildrenSection()` has a target. That is a separate, additive change from the grid fix above.

---

## Summary table

| Item | Result |
|------|--------|
| **1) File causing grid collapse** | `resources/views/matrimony/profile/wizard/sections/basic_info.blade.php` |
| **2) Line where structure breaks** | Line 41: marriage-history-container is opened **inside** the grid as the second grid item. |
| **3) Marriages inside wrong grid?** | Yes. It is a direct child of the 2‑column grid instead of a full-width block after the grid. |
| **4) Why height moves** | With marriage hidden, grid items are [Marital Status, Height, …]. With marriage shown, they are [Marital Status, Marriage block, Height, …], so Height becomes the third item and drops to the next row. |
| **5) Conditional swallowing children?** | No Blade conditional. `#children-section` is referenced by JS but no longer exists in the DOM (removed when marriages blade was replaced). |
| **6) Minimal structural fix** | Move `#marriage-history-container` (and the marriages include) out of the grid: delete it from between Marital Status and Height, and render it once **after** the grid’s closing `</div>` (after line 63). |
| **7) Fix applied?** | No. Diagnosis only. |
