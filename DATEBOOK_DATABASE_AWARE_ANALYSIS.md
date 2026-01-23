# DATEBOOK THEME - DATABASE-AWARE ADMIN ACTION ANALYSIS
## Complete Map of Admin Controls → Database Changes

---

## 1️⃣ TABLE RELATIONSHIP MAP

### PROFILE DATA STORAGE

**Primary Profile Storage:**
- **Table:** `wp_posts`
  - **Post Type:** `profile` (DATEBOOK_POST_TYPE)
  - **Key Columns:**
    - `ID` - Profile post ID (primary key)
    - `post_author` - User ID (links to `wp_users.ID`)
    - `post_status` - Visibility status (`publish`/`private`/`pending`)
    - `post_content` - Profile "About You" text
    - `post_title` - Profile name
    - `post_date` - Registration date
    - `post_modified` - Last modification date

**Profile Meta Data Storage:**
- **Table:** `wp_postmeta`
  - **Relationship:** `post_id` → `wp_posts.ID`
  - **Key Meta Keys:**
    - `profile_personal_data` - Serialized array containing all profile fields
      - Contains: `verified`, `upload_folder`, `gender`, `birthday`, `city`, `country`, `region`, etc.
    - `notactive` - Suspension flag (`0` = can activate, `1` = suspended)
    - `featured` - Featured status (`0`/`1`)
    - `featured_ends` - Featured expiration timestamp
    - `featured_ends_notice` - Featured expiration notice flag
    - `topad` - Top status (`0`/`1`)
    - `topad_ends` - Top expiration timestamp
    - `topad_ends_notice` - Top expiration notice flag
    - `new` - Moderation flag (`1` = pending review)
    - `edited_profile_content` - Pending edited content (if `suspend_profile_after_edit = 2`)
    - `notification_from_admin` - Admin notification text
    - `pass_not_sent` - Password generation flag
    - `profile_classifieds_id` - Links classifieds to profile
    - `profile_tour_id` - Links tours to profile
    - `profile_type` - Links comments to profile
    - `country`, `region`, `city` - Location IDs (link to `wp_datebook_countries.id`)

**User Account Storage:**
- **Table:** `wp_users`
  - **Key Columns:**
    - `ID` - User ID (primary key)
    - `user_login` - Username
    - `user_email` - Email address
    - `user_registered` - Registration date

**User Meta Storage:**
- **Table:** `wp_usermeta`
  - **Relationship:** `user_id` → `wp_users.ID`
  - **Key Meta Keys:**
    - `emailhash` - Email verification hash (if exists = not verified)
    - `profile_postid` - Links user to profile post (`wp_posts.ID`)
    - `profile_type` - User's profile type
    - `locale` - User's language preference

---

### VISIBILITY/STATUS FLAGS STORAGE

**Post Status (Visibility):**
- **Table:** `wp_posts.post_status`
- **Values:** `publish` (visible), `private` (hidden), `pending` (awaiting moderation)
- **Storage:** Direct column in `wp_posts` table

**Suspension Flag:**
- **Table:** `wp_postmeta`
- **Meta Key:** `notactive`
- **Values:** `0` (can activate), `1` (suspended, cannot activate)
- **Effect:** Overrides user's ability to activate profile

**Moderation Flag:**
- **Table:** `wp_postmeta`
- **Meta Key:** `new`
- **Values:** `1` (pending review), absent (reviewed)
- **Effect:** Controls appearance in moderation queue

**Featured/Top Status:**
- **Table:** `wp_postmeta`
- **Meta Keys:** `featured`, `topad` (`0`/`1`)
- **Expiration:** `featured_ends`, `topad_ends` (timestamps)
- **Effect:** Controls visibility priority in search/listings

---

### VERIFICATION/TRUST STORAGE

**Verification Badge:**
- **Table:** `wp_postmeta`
- **Meta Key:** `profile_personal_data` (serialized array)
- **Array Key:** `verified`
- **Values:** `0` (not verified), `1` (verified)
- **Storage:** Nested within serialized `profile_personal_data` array

**Email Verification:**
- **Table:** `wp_usermeta`
- **Meta Key:** `emailhash`
- **Values:** Hash string (exists = not verified), absent (verified)
- **Effect:** Controls email verification requirement

**Verification Photo:**
- **Table:** `wp_postmeta`
- **Meta Key:** `profile_personal_data` (serialized array)
- **Array Key:** `verification_confirm_photo`
- **Storage:** Image attachment ID stored in serialized array

---

### REPORTS STORAGE

**Reports Table:**
- **Table:** `wp_datebook_reports`
- **Structure:**
  - `id` - Report ID (primary key, auto-increment)
  - `name` - Reporter name (varchar 100)
  - `email` - Reporter email (varchar 100)
  - `reason` - Report reason (varchar 100)
  - `description` - Report description (text)
  - `user_id` - Reporter user ID (int 11, links to `wp_users.ID`)
  - `profile_id` - Reported profile ID (int 11, links to `wp_posts.ID`)
  - `date_submitted` - Submission timestamp (datetime)
  - `ip` - Reporter IP address (varbinary 16)
- **Relationships:**
  - `user_id` → `wp_users.ID` (manual, no foreign key)
  - `profile_id` → `wp_posts.ID` (manual, no foreign key)
- **No Status Fields:** No `viewed`, `resolved`, or `status` columns

---

### LOCATION DATA STORAGE

**Countries/Regions/Cities:**
- **Table:** `wp_datebook_countries`
- **Structure:**
  - `id` - Location ID (primary key)
  - `name` - Location name (longtext, serialized multilingual)
  - `slug` - URL slug (varchar 190)
  - `code` - Country code (varchar 2)
  - `parent_id` - Parent location ID (int 11, self-referential)
  - `level` - Hierarchy level (int 11: 0=country, 1=region, 2=city)
  - `counter` - Profile count (int 11)
  - `class_counter` - Classified count (int 11)
  - `tour_counter` - Tour count (int 11)
  - `active` - Active flag (int 11)
- **Relationships:**
  - `parent_id` → `wp_datebook_countries.id` (self-referential, no foreign key)
  - Profile location stored in `wp_postmeta` as `country`, `region`, `city` meta keys

---

### CONTENT STORAGE (Classifieds, Tours, Comments)

**Classifieds:**
- **Table:** `wp_posts`
- **Post Type:** `classifieds` (DATEBOOK_CLASSIFIEDS_TYPE)
- **Relationship:** `wp_postmeta.profile_classifieds_id` → `wp_posts.ID` (profile)

**Tours:**
- **Table:** `wp_posts`
- **Post Type:** `tour` (DATEBOOK_TOUR_TYPE)
- **Relationship:** `wp_postmeta.profile_tour_id` → `wp_posts.ID` (profile)

**Comments/Reviews:**
- **Table:** `wp_posts`
- **Post Type:** `profile_comment` (DATEBOOK_COMMENT_TYPE)
- **Relationship:** `wp_postmeta.profile_type` → `wp_posts.ID` (profile)

**Images:**
- **Table:** `wp_posts`
- **Post Type:** `attachment`
- **Relationship:** `post_parent` → `wp_posts.ID` (profile)
- **Meta Keys:**
  - `photo_type` - Image type identifier
  - `profile_photo` - Profile photo flag
  - `new` - Moderation flag
  - `declined` - Decline flag

---

### TABLES AFFECTED BY DELETE ACTIONS

**Profile Deletion Affects:**
1. `wp_posts` - Profile post deleted
2. `wp_postmeta` - All profile meta deleted (cascade)
3. `wp_posts` - All classifieds deleted (manual cascade)
4. `wp_posts` - All tours deleted (manual cascade)
5. `wp_posts` - All comments deleted (manual cascade)
6. `wp_postmeta` - All classified/tour/comment meta deleted (cascade)
7. Filesystem - Image files deleted (manual)
8. `wp_datebook_countries` - Location counters decremented (manual UPDATE)
9. `wp_users` - User account deleted (conditional, manual)
10. `wp_usermeta` - All user meta deleted (manual cascade)

**Image Deletion Affects:**
1. `wp_posts` - Attachment post deleted
2. `wp_postmeta` - Image meta deleted (cascade)
3. Filesystem - Image files deleted (manual)

**Classified/Tour Deletion Affects:**
1. `wp_posts` - Post deleted
2. `wp_postmeta` - Post meta deleted (cascade)

---

## 2️⃣ ADMIN ACTION → DB CHANGE TABLE

| Admin Action | Function/Method | Tables Touched | Columns/Meta Modified | Operations | Cascading Deletes | Orphaned Data Risk | Reversibility |
|--------------|----------------|----------------|----------------------|------------|-------------------|-------------------|---------------|
| **Suspend Profile** | `update_data()` → `wp_update_post()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_status` → `'private'`, `wp_postmeta.notactive` → `'1'` | UPDATE (2 tables) | NO | LOW (data preserved) | YES |
| **Unsuspend Profile** | `update_data()` → `wp_update_post()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_status` → `'private'`, `wp_postmeta.notactive` → `'0'` | UPDATE (2 tables) | NO | LOW | YES |
| **Activate Profile** | `update_data()` → `wp_update_post()` | `wp_posts`, `wp_postmeta`, `wp_usermeta` | `wp_posts.post_status` → `'publish'`, `wp_postmeta.notactive` → `'0'`, `wp_usermeta.emailhash` DELETED | UPDATE (2), DELETE (1) | NO | LOW | YES |
| **Deactivate Profile** | `update_data()` → `wp_update_post()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_status` → `'private'`, `wp_postmeta.notactive` → `'0'` | UPDATE (2 tables) | NO | LOW | YES |
| **Delete Profile** | `final_delete_profile()` | `wp_posts`, `wp_postmeta`, `wp_datebook_countries`, `wp_users`, `wp_usermeta`, Filesystem | Multiple: See cascade section | DELETE (multiple), UPDATE (1), Filesystem DELETE | YES (manual cascade) | HIGH (all data lost) | NO |
| **Grant Verified Badge** | `update_data()` → `update_post_meta()` | `wp_postmeta` | `wp_postmeta.profile_personal_data['verified']` → `'1'` | UPDATE (serialized array) | NO | LOW | YES |
| **Revoke Verified Badge** | `update_data()` → `update_post_meta()` | `wp_postmeta` | `wp_postmeta.profile_personal_data['verified']` → `'0'` | UPDATE (serialized array) | NO | LOW | YES |
| **Set Featured Status** | `update_data()` → `update_post_meta()` | `wp_postmeta` | `wp_postmeta.featured` → `'1'`, `wp_postmeta.featured_ends` → timestamp | UPDATE (2 meta keys) | NO | LOW | YES |
| **Cancel Featured Status** | `update_data()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.featured` → `'0'`, `wp_postmeta.featured_ends` DELETED, `wp_postmeta.featured_ends_notice` DELETED | UPDATE (1), DELETE (2) | NO | LOW | YES |
| **Set Top Status** | `update_data()` → `update_post_meta()` | `wp_postmeta` | `wp_postmeta.topad` → `'1'`, `wp_postmeta.topad_ends` → timestamp | UPDATE (2 meta keys) | NO | LOW | YES |
| **Cancel Top Status** | `update_data()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.topad` → `'0'`, `wp_postmeta.topad_ends` DELETED, `wp_postmeta.topad_ends_notice` DELETED | UPDATE (1), DELETE (2) | NO | LOW | YES |
| **Bypass Email Verification** | `update_data()` → `delete_user_meta()` | `wp_usermeta` | `wp_usermeta.emailhash` DELETED | DELETE (1 row) | NO | LOW (cannot restore hash) | NO |
| **Review Profile** | `moderate_approve_profile()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.new` DELETED | DELETE (1 row) | NO | LOW | NO (flag cannot be restored) |
| **Publish Profile from Queue** | `moderate_approve_profile()` → `wp_update_post()` | `wp_posts`, `wp_postmeta`, `wp_usermeta` | `wp_posts.post_status` → `'publish'`, `wp_postmeta.new` DELETED, `wp_usermeta.emailhash` DELETED | UPDATE (1), DELETE (2) | NO | LOW | YES |
| **Delete Profile from Queue** | `moderate_approve_profile()` → `final_delete_profile()` | Multiple (see Delete Profile) | Multiple | DELETE (cascade) | YES | HIGH | NO |
| **Review Image** | `moderate_approve_image()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.new` DELETED | DELETE (1 row) | NO | LOW | NO |
| **Decline Image** | `moderate_approve_image()` → `wp_update_post()` + `update_post_meta()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_status` → `'private'`, `wp_postmeta.new` DELETED, `wp_postmeta.declined` → `'1'` | UPDATE (2 tables), DELETE (1 row) | NO | LOW | YES |
| **Delete Image** | `moderate_approve_image()` → `wp_delete_attachment()` | `wp_posts`, `wp_postmeta`, Filesystem | Image post DELETED, meta DELETED, files DELETED | DELETE (cascade), Filesystem DELETE | YES (WordPress cascade) | HIGH (files lost) | NO |
| **Review Classified** | `moderate_approve_classified()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.new` DELETED | DELETE (1 row) | NO | LOW | NO |
| **Publish Classified** | `moderate_approve_classified()` → `wp_update_post()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_status` → `'publish'`, `wp_postmeta.new` DELETED | UPDATE (1), DELETE (1) | NO | LOW | YES |
| **Delete Classified** | `moderate_approve_classified()` → `wp_delete_post()` | `wp_posts`, `wp_postmeta` | Classified post DELETED, meta DELETED | DELETE (cascade) | YES (WordPress cascade) | MEDIUM | NO |
| **Review Tour** | `moderate_approve_tour()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.new` DELETED | DELETE (1 row) | NO | LOW | NO |
| **Publish Tour** | `moderate_approve_tour()` → `wp_update_post()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_status` → `'publish'`, `wp_postmeta.new` DELETED | UPDATE (1), DELETE (1) | NO | LOW | YES |
| **Delete Tour** | `moderate_approve_tour()` → `wp_delete_post()` | `wp_posts`, `wp_postmeta` | Tour post DELETED, meta DELETED | DELETE (cascade) | YES (WordPress cascade) | MEDIUM | NO |
| **Approve Edited Text** | `moderate_approve_modified_text()` → `wp_update_post()` | `wp_posts`, `wp_postmeta` | `wp_posts.post_content` UPDATED, `wp_postmeta.edited_profile_content` DELETED | UPDATE (1), DELETE (1) | NO | LOW (original content lost) | NO |
| **Decline Edited Text** | `moderate_approve_modified_text()` → `delete_post_meta()` | `wp_postmeta` | `wp_postmeta.edited_profile_content` DELETED | DELETE (1 row) | NO | LOW (edited content lost) | NO |
| **Send Admin Notification** | `update_data()` → `update_post_meta()` | `wp_postmeta` | `wp_postmeta.notification_from_admin` SET | UPDATE (1 row) | NO | LOW | YES |

---

## 3️⃣ HARD DELETE vs SOFT DELETE

### HARD DELETE (Permanent Row/File Deletion)

**Profile Deletion (`final_delete_profile()`):**
- **Type:** HARD DELETE
- **Tables Affected:**
  1. `wp_posts` - Profile post row DELETED
  2. `wp_postmeta` - All profile meta rows DELETED (WordPress cascade)
  3. `wp_posts` - All classifieds DELETED (manual loop)
  4. `wp_posts` - All tours DELETED (manual loop)
  5. `wp_posts` - All comments DELETED (manual loop)
  6. `wp_postmeta` - All classified/tour/comment meta DELETED (WordPress cascade)
  7. `wp_users` - User account DELETED (conditional, manual SQL)
  8. `wp_usermeta` - All user meta DELETED (manual SQL)
- **Filesystem:** Image directory DELETED
- **Recovery:** NO (permanent deletion)
- **SQL Operations:**
  - `wp_delete_post($profile_id, true)` - Hard delete profile
  - `wp_delete_post($classified_id, true)` - Hard delete each classified
  - `wp_delete_post($tour_id, true)` - Hard delete each tour
  - `wp_delete_post($comment_id, true)` - Hard delete each comment
  - `wp_delete_user($user_id)` - Delete user account
  - `DELETE FROM wp_usermeta WHERE user_id = $user_id` - Manual SQL delete
  - `DELETE FROM wp_users WHERE ID = $user_id` - Manual SQL delete
  - `deleteDirectory($dirtodelete)` - Filesystem deletion

**Image Deletion:**
- **Type:** HARD DELETE
- **Tables Affected:**
  1. `wp_posts` - Attachment post DELETED
  2. `wp_postmeta` - Image meta DELETED (WordPress cascade)
- **Filesystem:** Image files DELETED (all sizes)
- **Recovery:** NO
- **SQL Operations:**
  - `wp_delete_attachment($image_id, true)` - Hard delete attachment
  - `datebook_delete_all_photos()` - Filesystem deletion

**Classified/Tour Deletion:**
- **Type:** HARD DELETE
- **Tables Affected:**
  1. `wp_posts` - Post DELETED
  2. `wp_postmeta` - Post meta DELETED (WordPress cascade)
- **Recovery:** NO
- **SQL Operations:**
  - `wp_delete_post($post_id, true)` - Hard delete post

---

### SOFT DELETE (Flag-Based, Reversible)

**Profile Suspension:**
- **Type:** SOFT DELETE (flag-based)
- **Tables Affected:**
  1. `wp_posts` - `post_status` → `'private'`
  2. `wp_postmeta` - `notactive` → `'1'`
- **Recovery:** YES (reverse flags)
- **Data Preserved:** YES (all data intact)

**Image Decline:**
- **Type:** SOFT DELETE (flag-based)
- **Tables Affected:**
  1. `wp_posts` - `post_status` → `'private'`
  2. `wp_postmeta` - `declined` → `'1'`
- **Recovery:** YES (change status, remove flag)
- **Data Preserved:** YES (image file preserved)

**Profile Deactivation:**
- **Type:** SOFT DELETE (status change)
- **Tables Affected:**
  1. `wp_posts` - `post_status` → `'private'`
- **Recovery:** YES (change status back)
- **Data Preserved:** YES

---

### NO RECOVERY PATH ACTIONS

**Actions with NO Recovery:**
1. **Profile Deletion** - All rows deleted, files deleted, cannot restore
2. **Image Deletion** - Files permanently removed, cannot restore
3. **Classified/Tour Deletion** - Posts permanently deleted, cannot restore
4. **User Account Deletion** - User row deleted, cannot restore
5. **Email Hash Deletion** - Hash cannot be regenerated (but email can be re-verified)
6. **Review Flag Removal** - `new` flag deleted, cannot restore (but can manually re-add)

**Actions with LIMITED Recovery:**
1. **Edited Content Approval** - Original content replaced, cannot restore original
2. **Edited Content Decline** - Edited content deleted, cannot restore edited version

---

## 4️⃣ DATA INTEGRITY & RISK

### FOREIGN KEYS

**Finding:** NO FOREIGN KEY CONSTRAINTS FOUND

**Observations:**
- No `FOREIGN KEY` constraints in any Datebook tables
- No `REFERENCES` clauses in table definitions
- All relationships are manual/application-level
- WordPress core tables also lack foreign keys (WordPress design)

**Implications:**
- Database cannot enforce referential integrity
- Orphaned records possible
- Manual cascade logic required in code
- No automatic cascade deletes at database level

---

### MANUAL CASCADE LOGIC

**Profile Deletion Cascade (Manual):**
1. **Location Counter Updates:**
   - `UPDATE wp_datebook_countries SET counter = counter - 1 WHERE id = $country_id`
   - `UPDATE wp_datebook_countries SET counter = counter - 1 WHERE id = $region_id`
   - `UPDATE wp_datebook_countries SET counter = counter - 1 WHERE id = $city_id`
   - **Risk:** If location IDs invalid, counters become inaccurate
   - **Risk:** No transaction, partial updates possible

2. **Classifieds Deletion:**
   - Query: `WP_Query` with `meta_query` for `profile_classifieds_id = $profile_id`
   - Loop: `wp_delete_post($classified_id, true)` for each
   - **Risk:** If query fails, classifieds become orphaned
   - **Risk:** No transaction wrapping

3. **Tours Deletion:**
   - Query: `WP_Query` with `meta_query` for `profile_tour_id = $profile_id`
   - Loop: `wp_delete_post($tour_id, true)` for each
   - **Risk:** If query fails, tours become orphaned
   - **Risk:** No transaction wrapping

4. **Comments Deletion:**
   - Query: `WP_Query` with `meta_query` for `profile_type = $profile_id`
   - Loop: `wp_delete_post($comment_id, true)` for each
   - **Risk:** If query fails, comments become orphaned
   - **Risk:** No transaction wrapping

5. **Image Files Deletion:**
   - Retrieve `upload_folder` from `profile_personal_data`
   - Call `deleteDirectory($dirtodelete)`
   - **Risk:** If folder path invalid, files may remain
   - **Risk:** No rollback if database deletion succeeds but file deletion fails

6. **User Account Deletion:**
   - Conditional: Only if `profile_type == DATEBOOK_POST_TYPE`
   - `wp_delete_user($user_id)` - WordPress function
   - Manual SQL: `DELETE FROM wp_usermeta WHERE user_id = $user_id`
   - Manual SQL: `DELETE FROM wp_users WHERE ID = $user_id`
   - **Risk:** Redundant deletion (wp_delete_user already deletes user meta)
   - **Risk:** If condition fails, user account remains orphaned

---

### RISK OF ORPHANED RECORDS

**High Risk Scenarios:**

1. **Profile Deletion Failure Mid-Process:**
   - If deletion fails after classifieds deleted but before tours deleted
   - **Result:** Profile exists, classifieds orphaned, tours still linked
   - **Impact:** Data inconsistency, broken relationships

2. **Location Counter Mismatch:**
   - If profile deleted but location counter update fails
   - **Result:** Counter shows incorrect count
   - **Impact:** Statistics inaccurate

3. **Image Files Orphaned:**
   - If database deletion succeeds but filesystem deletion fails
   - **Result:** Database records deleted, files remain
   - **Impact:** Disk space waste, orphaned files

4. **User Account Orphaned:**
   - If profile deleted but user account deletion condition fails
   - **Result:** User account exists without profile
   - **Impact:** Orphaned user account

5. **Reports Orphaned:**
   - If profile deleted, reports table not cleaned
   - **Result:** `wp_datebook_reports.profile_id` points to non-existent profile
   - **Impact:** Broken report links, orphaned reports

6. **Meta Keys Orphaned:**
   - If `wp_posts` row deleted but `wp_postmeta` cascade fails
   - **Result:** Meta rows exist without parent post
   - **Impact:** Orphaned meta data (WordPress handles this, but risk exists)

---

### DATA INCONSISTENCY RISKS

**1. Serialized Array Corruption:**
- **Risk:** `profile_personal_data` stored as serialized PHP array
- **Impact:** If serialization fails, entire profile data corrupted
- **Location:** `wp_postmeta.meta_value` (longtext)
- **Mitigation:** None visible in code

**2. Counter Desynchronization:**
- **Risk:** `wp_datebook_countries.counter` manually maintained
- **Impact:** Counters can become inaccurate if updates fail
- **Location:** `wp_datebook_countries` table
- **Mitigation:** Manual UPDATE queries, no validation

**3. Status Flag Conflicts:**
- **Risk:** Multiple flags control visibility (`post_status`, `notactive`, `featured`)
- **Impact:** Conflicting states possible (e.g., `notactive=1` but `post_status=publish`)
- **Location:** `wp_posts.post_status`, `wp_postmeta.notactive`
- **Mitigation:** Code logic handles conflicts, but no database constraint

**4. Relationship Integrity:**
- **Risk:** `wp_postmeta.profile_classifieds_id` links to profile, but no foreign key
- **Impact:** Can link to non-existent profile
- **Location:** Meta keys linking posts
- **Mitigation:** Application-level validation only

**5. User-Profile Mismatch:**
- **Risk:** `wp_usermeta.profile_postid` can point to deleted profile
- **Impact:** User account orphaned from profile
- **Location:** `wp_usermeta` table
- **Mitigation:** Conditional deletion checks `profile_type`, but no validation

---

## 5️⃣ BLUEPRINT INPUT (CRITICAL)

### WHICH ADMIN CONTROLS ARE SAFE TO ALLOW

**SAFE CONTROLS (Low Risk, Reversible):**

1. **Profile Status Changes (Activate/Deactivate)**
   - **Why Safe:** Only updates `post_status`, reversible
   - **Safeguards Needed:** Mandatory notification, audit log
   - **Database Impact:** Single UPDATE operation

2. **Featured/Top Status Management**
   - **Why Safe:** Only meta flags, reversible, no data loss
   - **Safeguards Needed:** Expiration date mandatory, audit log
   - **Database Impact:** Meta updates only

3. **Verification Badge Grant/Revoke**
   - **Why Safe:** Only meta flag, reversible
   - **Safeguards Needed:** Reason field, notification, audit log
   - **Database Impact:** Serialized array update

4. **Content Moderation (Review Flags)**
   - **Why Safe:** Only removes flags, no data deletion
   - **Safeguards Needed:** Bulk action confirmations
   - **Database Impact:** Meta DELETE operations

5. **Image Decline**
   - **Why Safe:** Soft delete, reversible, files preserved
   - **Safeguards Needed:** Notification to user, reason field
   - **Database Impact:** Status change + flag

---

### WHICH MUST HAVE SAFEGUARDS

**CRITICAL SAFEGUARDS REQUIRED:**

1. **Profile Deletion**
   - **Current Risk:** Hard delete, no recovery, cascades to 10+ entities
   - **Required Safeguards:**
     - Soft delete with recovery period (30 days)
     - Transaction wrapping (all-or-nothing)
     - Mandatory reason field
     - Two-admin approval workflow
     - Email notification to user
     - Comprehensive audit log
     - Preview of all affected entities
     - Confirmation dialog with entity counts
   - **Database Changes:** Implement `deleted_at` timestamp instead of DELETE

2. **User Account Deletion**
   - **Current Risk:** Conditional deletion, manual SQL, redundant operations
   - **Required Safeguards:**
     - Separate from profile deletion
     - Confirmation dialog
     - Reason field
     - Audit log
     - Transaction wrapping
   - **Database Changes:** Use WordPress `wp_delete_user()` only, remove manual SQL

3. **Image Deletion**
   - **Current Risk:** Hard delete, files permanently removed
   - **Required Safeguards:**
     - Confirmation dialog
     - Reason field
     - Notification to user
     - Audit log
     - Soft delete option (move to trash)
   - **Database Changes:** Use WordPress trash instead of hard delete

4. **Bulk Actions**
   - **Current Risk:** No confirmation, no transaction wrapping
   - **Required Safeguards:**
     - Confirmation dialog with count
     - Preview of items
     - Rate limiting (max items)
     - Transaction wrapping
     - Audit log for bulk operations
   - **Database Changes:** Wrap in transactions

5. **Location Counter Updates**
   - **Current Risk:** Manual updates, no validation, no transaction
   - **Required Safeguards:**
     - Transaction wrapping
     - Validation of location IDs
     - Rollback on failure
     - Counter reconciliation job
   - **Database Changes:** Use database triggers or stored procedures

---

### WHICH SHOULD BE FORBIDDEN IN OUR SYSTEM

**FORBIDDEN CONTROLS:**

1. **Hard Delete Profile (Current Implementation)**
   - **Why Forbidden:** Permanent data loss, no recovery, cascades to 10+ entities
   - **Alternative:** Soft delete with recovery period
   - **Database Impact:** Use `deleted_at` timestamp, keep all data

2. **Manual SQL DELETE Statements**
   - **Why Forbidden:** Bypasses WordPress functions, no hooks, no cascade handling
   - **Alternative:** Use WordPress functions (`wp_delete_post`, `wp_delete_user`)
   - **Database Impact:** Let WordPress handle cascades

3. **Unconditional User Account Deletion**
   - **Why Forbidden:** Too aggressive, may want to preserve account
   - **Alternative:** Separate profile deletion from user deletion, require explicit action
   - **Database Impact:** Don't delete user in profile deletion cascade

4. **Filesystem Deletion Without Backup**
   - **Why Forbidden:** No recovery if database deletion succeeds but file deletion fails
   - **Alternative:** Move to trash directory, scheduled cleanup
   - **Database Impact:** Track file paths, implement soft delete

5. **Counter Updates Without Transactions**
   - **Why Forbidden:** Risk of partial updates, data inconsistency
   - **Alternative:** Use database transactions or triggers
   - **Database Impact:** Wrap in transactions or use triggers

6. **Serialized Array Updates Without Validation**
   - **Why Forbidden:** Risk of corruption, data loss
   - **Alternative:** Use JSON or separate meta keys, validate before update
   - **Database Impact:** Consider JSON column or normalized structure

---

### WHICH NEED AUDIT LOGS

**MUST HAVE AUDIT LOGS:**

1. **All Profile Status Changes**
   - Actions: Activate, Deactivate, Suspend, Unsuspend
   - Log: Action, admin ID, timestamp, before/after state, reason

2. **All Deletion Actions**
   - Actions: Profile delete, Image delete, Classified delete, Tour delete
   - Log: Action, admin ID, timestamp, entity ID, reason, affected entities count

3. **Verification Badge Changes**
   - Actions: Grant, Revoke
   - Log: Action, admin ID, timestamp, reason

4. **Featured/Top Status Changes**
   - Actions: Grant, Cancel, Expiration set
   - Log: Action, admin ID, timestamp, expiration date, reason

5. **Email Verification Bypass**
   - Actions: Delete emailhash
   - Log: Action, admin ID, timestamp, reason

6. **Bulk Actions**
   - Actions: Bulk approve, Bulk delete, Bulk moderate
   - Log: Action, admin ID, timestamp, item count, item IDs, reason

**AUDIT LOG TABLE STRUCTURE (Recommended):**
- `id` - Log entry ID
- `action` - Action type (varchar)
- `admin_id` - Admin user ID (bigint)
- `entity_type` - Entity type (varchar: profile/image/classified/tour)
- `entity_id` - Entity ID (bigint)
- `before_state` - JSON of before state
- `after_state` - JSON of after state
- `reason` - Reason text (text)
- `affected_count` - Number of entities affected (int)
- `affected_ids` - JSON array of affected IDs (text)
- `timestamp` - Action timestamp (datetime)
- `ip_address` - Admin IP (varbinary)
- `user_agent` - Admin user agent (text)

---

### WHICH NEED DELAYED EXECUTION / DUAL APPROVAL

**DELAYED EXECUTION REQUIRED:**

1. **Profile Deletion**
   - **Delay:** 30-day recovery period
   - **Implementation:** Soft delete, scheduled permanent deletion
   - **Dual Approval:** Two admins must approve permanent deletion

2. **User Account Deletion**
   - **Delay:** 7-day recovery period
   - **Implementation:** Soft delete, scheduled permanent deletion
   - **Dual Approval:** Two admins must approve permanent deletion

3. **Bulk Deletions (>10 items)**
   - **Delay:** 24-hour execution delay
   - **Implementation:** Queue for delayed execution
   - **Dual Approval:** Two admins must approve bulk deletion

**DUAL APPROVAL REQUIRED:**

1. **Profile Deletion (Permanent)**
   - **Requirement:** Two admins must approve
   - **Implementation:** Approval workflow table
   - **Database:** `wp_admin_approvals` table with `action`, `entity_id`, `admin1_id`, `admin2_id`, `status`

2. **User Account Deletion**
   - **Requirement:** Two admins must approve
   - **Implementation:** Approval workflow
   - **Database:** Approval tracking table

3. **Bulk Actions (>50 items)**
   - **Requirement:** Two admins must approve
   - **Implementation:** Approval workflow
   - **Database:** Approval tracking table

---

### RECOMMENDED DATABASE IMPROVEMENTS

**1. Add Foreign Key Constraints (If Possible):**
- `wp_datebook_reports.profile_id` → `wp_posts.ID`
- `wp_datebook_reports.user_id` → `wp_users.ID`
- `wp_postmeta.post_id` → `wp_posts.ID` (WordPress core, but consider)
- **Note:** May conflict with WordPress architecture

**2. Add Status Tracking Columns:**
- `wp_datebook_reports.status` - Report status (new/viewed/resolved/ignored)
- `wp_datebook_reports.viewed_at` - When report was viewed
- `wp_datebook_reports.resolved_at` - When report was resolved
- `wp_posts.deleted_at` - Soft delete timestamp
- `wp_posts.deleted_by` - Admin who deleted

**3. Normalize Serialized Data:**
- Consider extracting `profile_personal_data` into separate table
- Or use JSON column type instead of serialized PHP
- Reduces corruption risk

**4. Add Indexes:**
- `wp_datebook_reports.profile_id` - For report queries
- `wp_datebook_reports.user_id` - For reporter queries
- `wp_postmeta.meta_key` + `wp_postmeta.meta_value` - For meta queries
- `wp_datebook_countries.parent_id` - For location hierarchy

**5. Add Constraints:**
- `wp_datebook_countries.counter >= 0` - Prevent negative counts
- `wp_datebook_reports.profile_id > 0` - Validate profile ID
- `wp_datebook_reports.user_id > 0` - Validate user ID

**6. Implement Transactions:**
- Wrap profile deletion in transaction
- Wrap counter updates in transaction
- Rollback on any failure

---

**END OF DATABASE-AWARE ANALYSIS**
