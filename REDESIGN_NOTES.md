# CitiSense Admin — UI/UX Redesign Notes

The admin website now uses the same visual language as the CitiSense mobile app
(`UI_Mobile`): royal blue `#2563EB`, deep navy headings, slate body text, the
pale blue-grey page background `#F4F7FC`, white rounded cards, Roboto, pill
status badges and the new CitiSense mascot.

No database schema, authentication or dependency was changed. `api/`, `config/` and `vendor/` are untouched.

## Accept / Reject (Reports page)

- **Accept** moves a Pending report (and its repeat reports) to In Progress
  using the existing `update_report_status.php`. An optional completion date
  and message can be added in the same step.
- **Reject** uses the new `admin/reject_report.php`. Only Pending reports can
  be rejected, a reason is required, the reason is saved in `reporthistory`
  and sent to the resident as a notification.
- The "Rejected" status is found by name in the `stats` table. If it does not
  exist yet, the first rejection adds it as `STAT005` (one row of reference
  data; no table changes). To add it yourself instead:
  `INSERT INTO stats (statusID, statusName) VALUES ('STAT005', 'Rejected');`
- Rejected reports are left out of the Original/Duplicate grouping. This one
  line was added to the grouping query in `update_report_status.php`,
  `update_report_timeline.php`, `overdue_reports.php`, `reportView.php` and
  `partial/report_cycles.php`, so if the first report of a problem is
  rejected, the next report of that problem becomes the first one.

## How the front end is organised

| File                                      | Purpose                                                                                                                                                                                 |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `css/citisense.css`                       | Shared design system: colour/size tokens, layout, sidebar, buttons, forms, cards, tables, badges, alerts, modals, drawer, toasts, empty states, responsive rules. Loaded on every page. |
| `css/<page>.css`                          | Only the rules unique to that page.                                                                                                                                                     |
| `js/citisense.js`                         | Shared behaviour: phone menu, modals, confirmation dialogs (`data-confirm`), toasts.                                                                                                    |
| `js/reports.js`                           | Reports page: details panel, Accept, Reject, Mark as Resolved, expected completion date.                                                                                                |
| `admin/partial/head.php`                  | Shared `<head>` (title, favicon, fonts, icons, stylesheets).                                                                                                                            |
| `admin/partial/sidebar.php`               | Navigation with active-page highlight and a "needs action" count.                                                                                                                       |
| `admin/partial/footer.php`, `scripts.php` | Footer; toast area, confirm dialog and shared JS.                                                                                                                                       |
| `admin/partial/helpers.php`               | `csEscape()`, `csStatusBadge()`, `csDate()` and other view helpers.                                                                                                                     |
| `admin/partial/report_cycles.php`         | Read-only Original/Duplicate classification that mirrors `update_report_status.php`, so the Reports page only offers actions the backend accepts.                                       |

Status colours are defined once (`--cs-pending`, `--cs-progress`,
`--cs-resolved`, `--cs-overdue`) and every badge shows an icon and the written
status, never colour alone.

## Entry points

- `index.php` (new): logged in → `admin/dashboard.php`, otherwise → `admin/login.php`.
- `admin/index.php` (new): the same for `/admin/`, instead of a directory listing.

## Internet-dependent pieces

Roboto (Google Fonts), Bootstrap Icons, Chart.js and Leaflet still load from
their CDNs, as before. If the connection fails, the map and chart areas show a
plain message, and the Issue Map and Analytics pages still show the same
information as text lists.

## Status IDs

The pages keep using the existing IDs: `STAT001` Pending, `STAT002` In Progress,
`STAT003` Resolved, `STAT004` Overdue.
`Rejected` uses the status row named "Rejected" (added as `STAT005` if it is missing).

---

# November 2026 update (admin website only)

Nothing in the mobile app was changed. All changes are in the admin website files.

## 1. Pictures on announcements

- **Create / Edit Announcement** now has an "Add pictures" area (click or drag and drop).
  You can add up to 6 JPG, PNG, WebP or GIF pictures, each up to 5 MB.
- While editing, you can remove existing pictures (and undo the removal before saving).
- Pictures show on each announcement card. Click a picture to open it full size,
  and use the arrows (or the arrow keys) to move between pictures.
- Each upload is checked to make sure it is a real picture, resized to at most
  1600 px, and saved under a random name in `CITISENSE/uploads/announcements/`.
  Saving it again this way also removes hidden photo data (EXIF), such as GPS location.
  **The web server must be able to write to this folder.** On XAMPP/Windows it already can.
- Deleting an announcement also deletes its pictures, comments and read records.

## 2. Sidebar scroll line removed

The sidebar no longer shows its vertical scrollbar. The menu fits on common laptop
screens (it becomes more compact on short screens). If a screen is very short, the
menu can still be scrolled with the mouse wheel, just without the visible bar.

## 3. Report status can be changed after acceptance

- Reports that are **In Progress** or **Overdue** have a **Change Status** button,
  in the list and in the report details panel. The options are Resolved,
  Rejected (a reason is required and is sent to the resident) and Back to Pending.
- **Rejected** reports have a **Restore** button that moves them back to Pending.
- **Resolved** reports stay closed, as before.
- The resident gets a notification each time the status changes.

## 4. Possible duplicate reports (new page: Reports > Possible Duplicates)

CitiSense compares open reports (Pending, In Progress and Overdue) in your barangay:

| Check                                          | Points       |
| ---------------------------------------------- | ------------ |
| Pinned within 15 m / 30 m / 60 m of each other | 45 / 35 / 20 |
| Same type of issue                             | 20           |
| Similar description words                      | 15 or 8      |
| Very similar photo / somewhat similar photo    | 30 / 15      |

Two reports are suggested when they score **55 or more**, and they must be close
together or have a very similar photo. Related suggestions are shown as one group,
with a suggested main report (the one already being worked on, otherwise the oldest).

**Nothing is rejected automatically.** For each report, the admin chooses:

- **Mark as Duplicate**: the report is set to Rejected, and the resident gets a message
  saying which report it duplicates.
- **Not a Duplicate**: the pair is remembered and not suggested again.

Reports that are possible duplicates also show a yellow "Possible duplicate of …"
tag on the Reports page. The original "same category + same location" grouping
is unchanged.

Photo comparison uses a small fingerprint of each report photo (GD is required,
and XAMPP includes it). The fingerprints are saved in `report_image_hashes`, so each photo
is only read once.

## 5. Messages (two-way communication, admin side)

New page: **Community > Messages**: **one conversation per resident** (changed 22 Nov 2026).

- The list shows one entry per resident (photo, name, last message, which report it
  was about, and a red count of new messages), newest first. Search finds residents by
  name, email or report ID; searching a name also lists residents you have not talked to
  yet ("Start a new conversation"). The **Residents** page has a **Message** button too.
- A conversation combines every message about all of that resident's reports, oldest
  first. Each message about a report shows a small tag (e.g. "Report REP002 ·
  Streetlight") that opens the report.
- **Profile** (button in the conversation header) slides in the resident's email,
  contact number, member-since date and all their reports with status.
- When writing, **About** chooses where the message goes:
  - a report: saved in `report_messages` (the chat under that report in the app),
    as before, and the resident gets a notification;
  - **General message**: saved in the new table `resident_messages` (`barangayID`,
    `userID`, `senderType`, `senderID`, `message`), with a notification
    (`reportID` empty). If your `notification` table requires a report, the
    message is still saved and only the notification is skipped.
- "Open Messages" on a report opens that resident's conversation with the report
  already chosen under **About**.
- **Reply to a specific message** (like Messenger): point at a message and click
  **Reply**. A "Replying to …" bar appears above the message box (✕ or Esc cancels),
  and **About** switches to the same report as the original. The sent message shows
  a quote of the original; clicking the quote jumps to it.
- **Delete a message**: point at it and click **Delete**. It is removed for everyone
  (also in the app). Replies that quoted it then say "Original message was deleted".
- Read state is kept per resident (`message_reads.threadType = 'resident'`,
  `threadID = userID`). The **Messages** menu badge counts residents with new messages.
- Code: `admin/partial/resident_messages.php`, `admin/messages.php`,
  `admin/messages_api.php`, `js/messages.js`.

**Replies** (Messages and comments) are stored in the new table `message_replies`
(`itemType`, `itemID` → `parentType`, `parentID`; types `report_message`,
`resident_message`, `announcement_comment`). Existing tables are not changed, so the
app keeps working; it shows replies as normal messages until it also reads this table.

**For the mobile app:** to show general messages in the resident's chat with the
barangay, the app should read (and may write) `resident_messages` rows for the
resident's `userID`, together with their `report_messages`.

**Announcement comments** (table `announcement_comments`) are shown directly under
each announcement on the **Announcements** page, not in Messages (changed 22 Nov 2026):

- Each card has a comments button ("3 comments · 2 from residents", plus a red
  "N new" badge). Clicking it opens the comments under the announcement.
- **Replies to a specific comment** (like Facebook): every comment has **Reply**.
  Replies are shown indented under the comment; a reply to a reply goes in the same
  place and names the person it answers. Deleting a barangay comment also deletes the
  replies below it (the confirmation says how many).
- The admin can **write a comment** right there (Ctrl + Enter posts), **hide / show**
  a resident's comment (hidden comments are kept, not deleted), and **delete** the
  barangay's own comments. Everything updates without reloading the page.
- New resident comments are marked "New". Opening the comments marks them as read.
  The **Announcements** menu item shows how many announcements have new comments.
- Old links such as `messages.php?type=announcement&id=34` now open
  `announcements.php#comments-34`.
- Code: `admin/partial/announcement_comments.php` (loading and drawing comments),
  `admin/announcement_comments_api.php` (post / hide / delete / mark as read),
  section 4 of `js/announcements.js`.

**For the mobile app (not changed here):** to let residents post on announcements
and see the new pictures, the app should:

- insert resident comments into `announcement_comments` with `senderType='resident'`
  and `senderID=<userID>`;
- show rows where `isHidden = 0`;
- show the pictures from `announcement_images.imagePath`, relative to the
  `CITISENSE/` folder, ordered by `sortOrder`.

Report messages already use `report_messages`, which the app may already read and write.

## 6. Mascot animation

- **Login page:** the mascot fades in, floats gently, and has a soft glow and sparkles.
  It tilts slightly toward the mouse, looks away while the password is typed,
  nods when the email looks complete, and hops when you log in. It gives a small
  shake if the login fails. The Log In button shows "Logging in…" to prevent
  double clicks.
- **Dashboard:** the mascot (`css/images/mascot-walk-camera.svg`) plays a 13-second loop in
  front of a soft scene (clouds, a tree, houses, bushes): it walks in with bouncy steps, lifts a
  phone and snaps a photo with a soft flash, shows happy eyes, a little hop and a green
  "captured" check, then walks back and rests. The animation lives inside the SVG, so it works
  in a plain `<img>` tag anywhere. The earlier waving version is kept as
  `css/images/mascot-wave-animated.svg` for use elsewhere.
- **Other pages:** the mascots on Announcements, Settings and Messages
  float slowly and wave when you point at them.
- All animation is turned off for users whose device is set to reduce motion.

## Database changes

All changes **only add new tables**. No existing table or column is changed or removed,
so the mobile app keeps working.

| New table               | Purpose                                                                                              |
| ----------------------- | ---------------------------------------------------------------------------------------------------- |
| `announcement_images`   | Pictures for each announcement (`announceID`, `imagePath`, `sortOrder`)                              |
| `announcement_comments` | Resident comments and barangay replies on announcements (`senderType` resident/barangay, `isHidden`) |
| `message_reads`         | Records, for each barangay, which conversations have been read (drives the unread counts)            |
| `duplicate_reviews`     | The admin's "duplicate / not a duplicate" decisions                                                  |
| `report_image_hashes`   | Cached photo fingerprints for duplicate detection                                                    |

It also adds one row to `status`: **`STAT005` – Rejected**, but only if no status named
"Rejected" exists yet.

You don't need to run anything: the admin site creates any missing tables the first time
an admin opens Reports, Announcements, Messages or Possible Duplicates. To set it up manually instead (for example, if the database user
can't create tables), import
`database/citisense_update_2026_11.sql` in phpMyAdmin. It is safe to run more than once.

## Troubleshooting: Database Check page

Log in, then open **`admin/db_check.php`**
(e.g. `http://localhost/CITISENSE/admin/db_check.php`). It runs every query used
by the new features against your database and shows the exact error message
for anything that fails, plus your MariaDB version, table collations, GD/fileinfo
status and whether the picture folder can be written. It changes no data.

### Fix (21 Nov 2026): "Illegal mix of collations"

In the `citisense` database, `reports`, `users` and `announcements` use
`utf8mb4_unicode_ci`, but `report_messages` (and the new tables) use
`utf8mb4_general_ci`. MariaDB refuses to join columns of different
collations, which broke the Messages page (and the comment counts on
Announcements). Every such join now names the collation explicitly
(`... COLLATE utf8mb4_unicode_ci`). No table had to be changed.

### Fix (21 Nov 2026): report photos not showing

The database stores paths like `uploads/reports/REP001.jpg`, but those files are
saved by the **mobile app's API**, which is usually a different folder in `htdocs`
than `CITISENSE`. The admin site was only looking in `CITISENSE/uploads/...`.

The admin site now searches for each photo in:

1. `CITISENSE/`
2. `CITISENSE/api/`
3. any folders listed in `CS_MEDIA_ROOTS` (`admin/partial/helpers.php`)
4. every other folder in `htdocs` that contains an `uploads` folder
   (e.g. `htdocs/citisense_api/uploads`, `htdocs/x/api/uploads`)
5. `CITISENSE/admin/` (admin profile pictures)

Photos found outside `CITISENSE` are sent by `admin/media.php`. It works only for
logged-in admins, only for picture files, and only inside those folders. Duplicate
photo comparison uses the same search.

If the photos are on another computer or a hosted server, set `CS_MEDIA_BASE_URL`
in `admin/partial/helpers.php` to that folder's web address instead.
**Database Check** (`admin/db_check.php`) shows which folder the photos were found
in, or which ones are missing and where it looked.

## Fixes (23 Nov 2026)

* **Change Status → Back to New** no longer asks for a message: the report simply returns to Pending (the server also ignores a message for this).
* **Report details (View)** now follow the report form of the CitiSense app: numbered sections **1 Photo · 2 Issue Category · 3 Location · 4 Description** ("What the resident said" is now "Description"). Location shows a small map with a pin (Leaflet + OpenStreetMap, like the app) and an "Open in Google Maps" link.
* **Download Summary (PDF / Word / CSV)**: `config/database.php` started with an empty line before `<?php`, which was sent before every file and broke the downloads. Removed; the export also clears any stray output before sending a file. PDF and Word need the Composer packages: the hosting must run `composer install` (the `vendor/` folder is not in Git).
* **Location** is no longer shown on the Reports and Possible Duplicates lists, only in View / View Report.
* **Report cards** (Reports, Possible Duplicates) list their details one per line with labels (Report ID, Type of issue, Submitted, Reported by).
* **Expected completion date** cannot be earlier than the day the report was accepted (the latest move to In Progress); the same day is allowed. Checked in the browser and on the server.
* **Uploads on hosting (Railway volume)**, admin service environment variables:
  * If the volume is mounted on the site's `uploads` folder (Railway: **`/app/uploads`**), nothing needs to be set: admin profile pictures (`uploads/profile_pictures/`) and announcement pictures (`uploads/announcements/`) are saved there. Leave `CS_UPLOAD_ROOT` empty in that case.
  * `CS_UPLOAD_ROOT` (only for a volume mounted elsewhere): the volume's mount path (e.g. `/data`). Admin profile pictures and announcement pictures are saved there and served through `admin/media.php`.
  * `CS_MEDIA_FALLBACK_URL`: the mobile API's address (e.g. `https://citisense-api-production.up.railway.app/`). Residents' report photos and profile pictures that are not on the admin server are loaded from there.

## Improvements (23 Nov 2026)

* **Summary report**: Blocked Drainage is left out of the downloaded summary (PDF / Word / CSV: totals, category and status breakdowns, report list) and of the older `generate_report.php`. The category itself is unchanged.
* **Issue Map**: hovering (or tapping) a hotspot shows its address, the total number of reports and the count per category.
* **Passwords** (`admin/partial/passwords.php`, `js/password-rules.js`): 10–64 characters, only letters, numbers and keyboard symbols (no spaces, emojis or other Unicode), with an uppercase and a lowercase letter, a number and a symbol, and not containing the email name. Checked in the browser (live checklist) and always again on the server. Used by Settings › Change password and by Forgot password. Passwords are stored with `password_hash()`.
* **Login**: "Welcome back to CitiSense, Admin!", a link to Forgot password, and a faint barangay skyline (houses, buildings, trees, clouds) on the blue panel. The password is now checked before the account type is revealed, and the session ID is renewed after login.
* **Forgot password for administrators** (`admin/forgot_password.php`): email → 6-digit code → new password. It reuses the residents' recovery of the mobile API (`forgot_password.php` sends the code, `verify_otp.php` checks it; codes expire after 5 minutes, 5 tries, 1 per minute) and saves the new password on the admin site with the admin password rules; all reset records are then deleted, so a code cannot be used twice. Only admin accounts get an email; the page shows the same message for every email address.
  * Admin service variable: `CS_API_URL` = the mobile API address (if not set, `CS_MEDIA_FALLBACK_URL` is used). The email account is configured on the API service.
* **Possible Duplicates**: the suggested main report has no "Mark as Duplicate" button, and the server refuses to mark it (`duplicate_review.php`, action `check_duplicate`, asked before anything is closed).
* **Messages**: report replies use the same notification text as the mobile API, so the app does not show them twice; "About" defaults to the resident's latest report (general messages are only delivered as a notification because the app has no chat for them yet); an open conversation updates itself every 15 seconds.
* **Layout**: dropdowns and date pickers look the same on all phones; the report details buttons wrap on small phones; the Dashboard map loads from the same CDN as the other maps.

## Duplicate reports follow their main report; Issue Map groups within 60 m (24 Nov 2026)

* **Mark as Duplicate no longer rejects the report.** It links the report to the main report
  (`duplicate_reviews`, `decision = 'linked'`, `reportA` = duplicate, `reportB` = main; no new table) and
  gives it the main report's current status. Its resident is notified that the report follows the main report.
* **Status sync** (`admin/partial/duplicate_links.php`, `csSyncLinkedDuplicates()`): every status change of a
  main report is copied to its linked duplicates in the same database transaction, with a history entry and a
  notification for each resident (shown in the mobile app). Used by: Accept / Resolve / Back to New / Reject
  (`update_report_status.php`), Reject of a Pending report (`reject_report.php`), new completion date and
  Overdue → In Progress (`update_report_timeline.php`), and the automatic Overdue check (`overdue_reports.php`).
  The expected completion date is copied too.
* A linked duplicate cannot be changed on its own (the endpoints answer with which report to change instead).
  On the Reports page it shows "Duplicate of report … · follows its status" and only **View**; the main report
  shows how many duplicates follow it. Linked reports are no longer suggested on Possible Duplicates.
* Linking never forms chains or circles: linking to a report that is itself a duplicate uses its main report,
  and a main report cannot become the duplicate of its own duplicate.
* Reports marked as duplicates **before** this change were rejected; they stay Rejected (use Restore to New and
  mark them again if needed).
* **Issue Map**: report locations within 60 m of each other (directly or through a chain of nearby places) are
  shown as one pin with the total number of reports; hovering or tapping it shows the count per category.
  The "Places with the most reports" list uses the same groups. Grouping is calculated on every page load and
  only affects the display; reports keep their own locations in the database.

### Update (24 Nov 2026): marked duplicates stay visible until the main report is settled
* **Expected completion date**: a duplicate gets the main report's date when it is marked (if the main report
  already has one) and every time the date changes.
* **View on the main report** lists its "Duplicate reports (N)": photo, ID, type, date, resident, place,
  status and a View button for each.
* **Possible Duplicates**: after Mark as Duplicate the report stays in its group, marked "Marked as duplicate of
  report …", with a "Next:" note and an "Open report" button. The group leaves the page only when the main
  report is **accepted and has an expected completion date**. Reports marked after that leave at once.

## Resident feedback on resolved reports (24 Nov 2026)

The CitiSense app lets a resident answer, once a report is Resolved: is it fixed (yes/no), a 1–5 rating and an
optional comment (table `report_feedback`, written by the mobile API). The admin site now shows it:
* **Reports › Resident Feedback** (`admin/feedback.php`): every feedback, newest first, with filters All /
  Not fixed / Fixed, a summary (count, average rating, "not fixed" count), and buttons to open the report or
  message the resident. Feedback that is "not fixed" has a red edge.
* **Menu badge**: number of feedback that is new or was changed since the page was last opened (remembered in
  `message_reads`, threadType 'feedback'). Opening the page clears it.
* **Reports page**: resolved reports show "Resident feedback: fixed / not fixed · N/5"; their View has a
  "Resident feedback" section with the answer, stars and comment.
* "Not fixed" answers also appear in Messages, as before (the app adds them to the report's conversation).
* No database change; works (shows "No feedback yet") before the table exists.

## Fixes and improvements (25 Nov 2026)

### View Report opens the report where you are
* **View / View Report** on Possible Duplicates, Resident Feedback, Issue Map, Dashboard and Messages now
  opens the report's details panel **on that page**. It no longer jumps to the Reports page or to its
  **All** tab. On the Reports page, opening a report never changes the tab, filters or page number.
* Reports that are not on the current page are loaded from the new **`admin/report_details.php`**
  (JSON, only the admin's own barangay). The panel and its dialogs (Accept, Reject, Resolve, Change
  Status) are shared: `admin/partial/report_drawer.php`, data from `admin/partial/report_details.php`,
  behaviour in `js/reports.js` (any element with `data-view="REPORTID"`).
* The open report is kept in the address (`?open=REP001`): a refresh, or the reload after an action,
  shows the same report on the same page. Old links such as `reports.php?open=REP001` still work.

### Duplicates: only confirmed ones are "Duplicate of …"
* **Possible Duplicates**: after **Mark as Duplicate** the report leaves the page at once (no more
  "Marked as duplicate of …" line or "Open report" note). The link to its main report stays in
  `duplicate_reviews` (`decision = 'linked'`).
* **Reports page**: confirmed duplicates are no longer separate entries (in any tab, and not counted
  in the tab numbers). They are listed in **View** of their main report under "Confirmed duplicate
  reports". Searching for a duplicate's ID shows its main report.
* Labels: **Suggested main report** (has confirmed duplicates but is not accepted yet, or is the
  suggested main report of a possible-duplicate group) and **Main report** (accepted). The suggestion
  check says "Possible duplicate of … · not confirmed" with a Review link.
* "Duplicate of report …" appears **only** for reports an administrator marked. The older automatic
  grouping (same issue type at the same saved location) now says "Same place and type of issue as
  report …" instead of "Same problem as …".

### Dates and times
* One rule for the whole admin site (`config/timezone.php`, loaded by `config/database.php`):
  dates are **stored in UTC** and **shown in Philippine time (Asia/Manila)**.
  * The MySQL connection uses the storage timezone (`SET time_zone`), so `NOW()` and `TIMESTAMP`
    columns agree with PHP.
  * Everything the admin site saves uses `csDbNow()` / `csToDb()`; everything it shows goes through
    `csDate()` / `csTimeAgo()`, which convert to Philippine time. "4 hours ago" and the clock time now
    match, and refreshing never changes them.
  * The expected completion date the admin picks (Philippine time) is saved in UTC; the Reports date
    filters, Analytics (grouping by day) and the downloaded summary use Philippine calendar days.
* Optional environment variables: `CS_DB_TIMEZONE` (default `UTC`) and `CS_DISPLAY_TIMEZONE`
  (default `Asia/Manila`). **The mobile API must save dates in the same timezone.** If it saves
  Philippine time instead, set `CS_DB_TIMEZONE=Asia/Manila`. **Database Check** (`admin/db_check.php`)
  now shows both clocks and the newest report's stored/shown time, and warns when reports appear to be
  in the future.
* Expected completion dates saved **before** this change were stored as Philippine time; open
  reports may show them 8 hours later until the date is saved again.

### Issue Map
* A place's **View** now opens a list of **every** report counted in its pin (all places within 60 m),
  with All / Open only, and each report opens in place. Clicking a hotspot on the map opens the same
  list. Before, View searched the Reports page for one address only, so most reports were missing.
* "Open" means Pending, In Progress or Overdue (Rejected reports are no longer counted as open). The
  pin, the list and the View panel use the same report IDs, so the numbers always match.
* The sidebar menu is no longer covered by the map: every map gets its own stacking context
  (`isolation: isolate`), so Leaflet's layers (z-index 400–1000) stay inside the map.

### Administrator email
* No admin email is hard-coded in this website: it is the login email stored in `users`. Run
  `database/update_admin_email_2026_11.sql` once to change `07dablo.christille@gmail.com` to
  `brgyduljo.fatima@gmail.com` (only that admin account). If the **mobile API's** mail settings
  (sender / SMTP user on Railway) use the old address, change them there too.

### Layout
* **Dropdowns**: every `select.cs-select` becomes the same custom dropdown on every device (iPhone
  included): `CitiSense.enhanceSelect()` in `js/citisense.js`. The real `<select>` stays underneath,
  so forms, `required`, `select.value` and `change` events keep working. Keyboard: arrows, Home/End,
  Enter, Esc, type to jump. The list opens upward when there is no room below and is never cut off
  by a dialog. Add `data-native` to a select to keep the browser's own picker.
* **No overflow**: long words (emails, addresses, IDs) wrap inside cards, dialogs and the details
  panel; wide tables scroll inside their card; buttons wrap on phones. The Reports tabs show as a
  3 × 2 grid on phones, and on tablets the chosen tab is scrolled into view.
* **Login on phones and tablets** keeps the desktop design: the blue brand panel (clouds, skyline,
  badge, tagline) becomes a header and the form sits in a white card over it. It fits a 390 × 844
  phone without scrolling. The forgot-password page uses the same layout.
* **Dashboard › Report summary**: each card lays out by its own width (CSS container query). Narrow
  cards: icon, number and label stacked and centred. Wide cards: one centred row. Phones: Total
  Reports across the top, then two by two. Tablets: two wide cards, then three.

No database table or column was changed.

### Issue Map hotspots: numbers on every spot (25 Nov 2026)
* Every spot drawn on the heatmap now shows its total and the count per category: hover with a mouse,
  or tap on a phone/tablet. Before, there was one hover area per group, at the group's centre, so
  spots of a group that covers several places within 60 m showed nothing.
* Each saved location with reports has its own hover/tap area (about the size of the drawn spot),
  showing its group's numbers (places within 60 m are counted together, as in the list).
* Mouse: hover shows the numbers, click opens the list of reports. Touch: the first tap shows the
  numbers, a second tap on the same spot opens the list, tapping empty map closes the numbers.
* The tooltip always stays inside the map: below the spot when there is no room above, moved sideways
  near the left/right edges, and moved over the spot on very small maps.
