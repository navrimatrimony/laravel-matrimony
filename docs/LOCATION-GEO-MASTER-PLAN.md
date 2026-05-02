GOAL:
Build a single, normalized, future-proof location engine for India.
No duplicate tables (no separate villages/cities tables).
Everything must be driven from one unified table: places.

CORE PRINCIPLE:
Database is the source of truth.
No UI-based guessing.
Each location must explicitly define its type.

----------------------------------------

TABLE: places

FIELDS:

- id (bigint, primary)
- name (string, indexed)
- slug (string, unique)
- type (enum):
    country
    state
    district
    taluka
    city
    suburb
    village

- parent_id (nullable, FK to places.id)
- level (int)  // 0=country, 1=state, 2=district, etc.
- state_code (string, nullable)
- district_code (string, nullable)

- is_active (boolean, default true)
- created_at
- updated_at

----------------------------------------

HIERARCHY RULE:

country
 └── state
      └── district
           └── taluka
                └── city / suburb / village

----------------------------------------

STRICT RULES:

1. NO separate tables like:
   - villages
   - cities
   - districts

2. ALL must live inside places table.

3. type is mandatory and never guessed.

4. parent_id defines hierarchy.

5. level must match hierarchy depth.

----------------------------------------

DISPLAY LOGIC (CRITICAL):

Based on type:

IF type = village:
  show: village, taluka, district

IF type = suburb:
  show: suburb, city OR district

IF type = city:
  show: city, state

----------------------------------------

EXAMPLES:

Maharashtra → type=state
Pune → type=district
Haveli → type=taluka
Wakad → type=suburb
Ambegaon Khurd → type=village
Pune City → type=city

----------------------------------------

NORMALIZATION RULES:

Input variations:
- "pune", "punee", "poone"

Must map to:
→ Pune (type=city or district depending on match)

No duplicates allowed.

----------------------------------------

INDEXING:

- index(name)
- unique(slug)
- index(parent_id)
- index(type)

----------------------------------------

FUTURE READY:

This system must support:
- OCR input normalization
- AI matching
- distance search
- multilingual labels (later phase)

----------------------------------------

## PINCODE SYSTEM (MANDATORY)

Purpose:
- Provide precise geographic identity
- Enable future nearby search
- Resolve same-name ambiguity
- Improve normalization accuracy

----------------------------------------

TABLE: pincodes

FIELDS:

- id (bigint, primary)
- pincode (string, indexed)
- place_id (FK to places.id)
- latitude (decimal, nullable)
- longitude (decimal, nullable)
- is_primary (boolean, default false)
- created_at
- updated_at

----------------------------------------

RELATIONSHIPS:

Place → hasMany pincodes
Pincode → belongsTo place

----------------------------------------

RULES:

1. A place can have multiple pincodes
2. A pincode belongs to only one place
3. (pincode, place_id) must be unique
4. Do NOT store pincode inside places table
5. `places` = hierarchy layer
   `pincodes` = precision layer

----------------------------------------

USAGE GUIDELINES:

- Location search may optionally include pincode
- Same-name places must be disambiguated using pincode
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
- No dynamic guessing of location type
- No duplicate entries
- No merging pincodes into places
- No duplicate pincode storage
- No UI-level location guessing

----------------------------------------

SUCCESS CRITERIA:

- Any place can be uniquely identified
- Any hierarchy can be traversed upward
- Display labels are generated purely from DB structure
- System works for all Indian locations (village to metro)
- Each place maps to one or more pincodes
- System can resolve ambiguity using pincode
