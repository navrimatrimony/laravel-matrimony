GOAL:
Build a single, normalized, future-proof location engine for India.
No duplicate tables (no separate villages/cities tables).
Everything must be driven from one unified table: addresses.

CORE PRINCIPLE:
Database is the source of truth.
No UI-based guessing.
Each location must explicitly define its hierarchy type and classification tag.

----------------------------------------

TABLE: addresses

FIELDS:

- id (bigint, primary)
- name (string, indexed)
- slug (string, unique)
- type (enum):
    country
    state
    district
    taluka
    village

- tag (enum, nullable/default null):
    city
    suburban
    rural

- parent_id (nullable, FK to addresses.id)
- level (int)  // 0=country, 1=state, 2=district, etc.
- pincode (string, nullable)
- lat (decimal, nullable)
- lng (decimal, nullable)
- lgd_code (string, nullable)

- is_active (boolean, default true)
- created_at
- updated_at

----------------------------------------

HIERARCHY RULE:

country
 └── state
      └── district
           └── taluka
                └── village

----------------------------------------

STRICT RULES:

1. NO separate tables like:
   - villages
   - cities
   - districts

2. ALL must live inside addresses table.

3. hierarchy is hierarchy-only and mandatory. Allowed values:
   - country
   - state
   - district
   - taluka
   - village

4. tag is classification-only. Allowed values:
   - city
   - suburban
   - rural

5. parent_id defines hierarchy.

6. level must match hierarchy depth.

----------------------------------------

DISPLAY LOGIC (CRITICAL):

Based on hierarchy + tag:

IF hierarchy = village:
  IF tag = city:
    show: village/city display name, state
  IF tag = suburban:
    show: suburb/locality, city OR taluka/district
  IF tag = rural:
    show: village, taluka, district

----------------------------------------

EXAMPLES:

Maharashtra → hierarchy=state
Pune → hierarchy=district
Haveli → hierarchy=taluka
Wakad → hierarchy=village, tag=suburban
Ambegaon Khurd → hierarchy=village, tag=rural
Pune City → hierarchy=village, tag=city

----------------------------------------

NORMALIZATION RULES:

Input variations:
- "pune", "punee", "poone"

Must map to:
→ Pune (hierarchy=village, tag=city OR hierarchy=district depending on match)

No duplicates allowed.

----------------------------------------

INDEXING:

- index(name)
- index(parent_id)
- index(hierarchy)
- unique(parent_id, hierarchy, slug)

----------------------------------------

FUTURE READY:

This system must support:
- OCR input normalization
- AI matching
- distance search
- multilingual labels (later phase)

----------------------------------------

## PINCODE / GEO PRECISION (MANDATORY)

Purpose:
- Provide precise geographic identity
- Enable future nearby search
- Resolve same-name ambiguity
- Improve normalization accuracy

----------------------------------------

TABLE: addresses

FIELDS:

- pincode (string, nullable, indexed when needed)
- lat (decimal, nullable)
- lng (decimal, nullable)
- lgd_code (string, nullable)

----------------------------------------

RELATIONSHIPS:

Address → belongsTo parent address via parent_id
Address → hasMany child addresses via parent_id

----------------------------------------

RULES:

1. A hierarchy row may store one pincode when the source data provides it.
2. pincode, lat, lng, and lgd_code belong on addresses rows.
3. Do NOT create separate city/suburb/village hierarchy tables.
4. `addresses.hierarchy` = country/state/district/taluka/village hierarchy layer.
5. `addresses.tag` = nullable city/suburban/rural classification layer.

----------------------------------------

USAGE GUIDELINES:

- Location search may optionally include pincode
- Same-name addresses must be disambiguated using parent hierarchy and pincode
- Future nearby search will use latitude/longitude

----------------------------------------

FUTURE CAPABILITIES:

- Nearby profile search
- Radius-based filtering
- Smart locality matching
- OCR-based correction

----------------------------------------

NON-NEGOTIABLE:

- No UI-based concatenation logic
- No dynamic guessing of location hierarchy
- No duplicate entries
- No duplicate pincode storage
- No UI-level location guessing

----------------------------------------

SUCCESS CRITERIA:

- Any address row can be uniquely identified
- Any hierarchy can be traversed upward
- Display labels are generated purely from DB structure
- System works for all Indian locations (village to metro)
- Each address row may carry pincode/lat/lng/lgd_code when source data provides it
- System can resolve ambiguity using pincode
