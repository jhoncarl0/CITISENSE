# CitiSense Admin — UI/UX Redesign Notes

The admin website now uses the same visual language as the CitiSense mobile app
(`UI_Mobile`): royal blue `#2563EB`, deep navy headings, slate body text, the
pale blue-grey page background `#F4F7FC`, white rounded cards, Roboto, pill
status badges and the new CitiSense mascot.

No database schema, authentication or dependency was changed. `api/`, `config/` and `vendor/` are untouched.

## Accept / Reject (Reports page)

* **Accept** moves a Pending report (and its repeat reports) to In Progress
  using the existing `update_report_status.php`. An optional completion date
  and message can be added in the same step.
* **Reject** uses the new `admin/reject_report.php`. Only Pending reports can
  be rejected, a reason is required, the reason is saved in `reportHistory`
  and sent to the resident as a notification.
* The "Rejected" status is found by name in the `stats` table. If it does not
  exist yet, the first rejection adds it as `STAT005` (one row of reference
  data; no table changes). To add it yourself instead:
  `INSERT INTO stats (statusID, statusName) VALUES ('STAT005', 'Rejected');`
* Rejected reports are left out of the Original/Duplicate grouping. This one
  line was added to the grouping query in `update_report_status.php`,
  `update_report_timeline.php`, `overdue_reports.php`, `reportView.php` and
  `partial/report_cycles.php`, so if the first report of a problem is
  rejected, the next report of that problem becomes the first one.

## How the front end is organised

| File | Purpose |
| --- | --- |
| `css/citisense.css` | Shared design system: colour/size tokens, layout, sidebar, buttons, forms, cards, tables, badges, alerts, modals, drawer, toasts, empty states, responsive rules. Loaded on every page. |
| `css/<page>.css` | Only the rules unique to that page. |
| `js/citisense.js` | Shared behaviour: phone menu, modals, confirmation dialogs (`data-confirm`), toasts. |
| `js/reports.js` | Reports page: details panel, Accept, Reject, Mark as Resolved, expected completion date. |
| `admin/partial/head.php` | Shared `<head>` (title, favicon, fonts, icons, stylesheets). |
| `admin/partial/sidebar.php` | Navigation with active-page highlight and a "needs action" count. |
| `admin/partial/footer.php`, `scripts.php` | Footer; toast area, confirm dialog and shared JS. |
| `admin/partial/helpers.php` | `csEscape()`, `csStatusBadge()`, `csDate()` and other view helpers. |
| `admin/partial/report_cycles.php` | Read-only Original/Duplicate classification that mirrors `update_report_status.php`, so the Reports page only offers actions the backend accepts. |

Status colours are defined once (`--cs-pending`, `--cs-progress`,
`--cs-resolved`, `--cs-overdue`) and every badge shows an icon and the written
status, never colour alone.

## Entry points

* `index.php` (new): logged in → `admin/dashboard.php`, otherwise → `admin/login.php`.
* `admin/index.php` (new): the same for `/admin/`, instead of a directory listing.

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

* **Create / Edit Announcement** now has an "Add pictures" area (click or drag and drop).
  You can add up to 6 JPG, PNG, WebP or GIF pictures, each up to 5 MB.
* While editing, you can remove existing pictures (and undo the removal before saving).
* Pictures show on each announcement card. Click a picture to open it full size,
  and use the arrows (or the arrow keys) to move between pictures.
* Each upload is checked to make sure it is a real picture, resized to at most
  1600 px, and saved under a random name in `CITISENSE/uploads/announcements/`.
  Saving it again this way also removes hidden photo data (EXIF), such as GPS location.
  **The web server must be able to write to this folder.** On XAMPP/Windows it already can.
* Deleting an announcement also deletes its pictures, comments and read records.

## 2. Sidebar scroll line removed

The sidebar no longer shows its vertical scrollbar. The menu fits on common laptop
screens (it becomes more compact on short screens). If a screen is very short, the
menu can still be scrolled with the mouse wheel, just without the visible bar.

## 3. Report status can be changed after acceptance

* Reports that are **In Progress** or **Overdue** have a **Change Status** button,
  in the list and in the report details panel. The options are Resolved,
  Rejected (a reason is required and is sent to the resident) and Back to Pending.
* **Rejected** reports have a **Restore** button that moves them back to Pending.
* **Resolved** reports stay closed, as before.
* The resident gets a notification each time the status changes.

## 4. Possible duplicate reports (new page: Reports > Possible Duplicates)

CitiSense compares open reports (Pending, In Progress and Overdue) in your barangay:

| Check | Points |
|---|---|
| Pinned within 15 m / 30 m / 60 m of each other | 45 / 35 / 20 |
| Same type of issue | 20 |
| Similar description words | 15 or 8 |
| Very similar photo / somewhat similar photo | 30 / 15 |

Two reports are suggested when they score **55 or more**, and they must be close
together or have a very similar photo. Related suggestions are shown as one group,
with a suggested main report (the one already being worked on, otherwise the oldest).

**Nothing is rejected automatically.** For each report, the admin chooses:

* **Mark as Duplicate**: the report is set to Rejected, and the resident gets a message
  saying which report it duplicates.
* **Not a Duplicate**: the pair is remembered and not suggested again.

Reports that are possible duplicates also show a yellow "Possible duplicate of …"
tag on the Reports page. The original "same category + same location" grouping
is unchanged.

Photo comparison uses a small fingerprint of each report photo (GD is required,
and XAMPP includes it). The fingerprints are saved in `report_image_hashes`, so each photo
is only read once.

## 5. Messages (two-way communication, admin side)

New page: **Community > Messages**.

* **Report conversations** use the existing `report_messages` table. The admin
  can read what residents wrote about a report and reply. A reply sends the resident a
  notification. A conversation can also be started from a report's details panel
  ("Open Messages").
* **Announcement comments** use the new `announcement_comments` table. The admin can
  read comments, reply, and hide or unhide inappropriate comments.
  Hidden comments are kept, not deleted.
* The sidebar shows how many conversations have new resident messages. A
  conversation counts as read once an admin opens it or replies.
  This is shared by all admins of the same barangay.
* Each announcement card shows how many comments it has, with a link to them.

**For the mobile app (not changed here):** to let residents post on announcements
and see the new pictures, the app should:

* insert resident comments into `announcement_comments` with `senderType='resident'`
  and `senderID=<userID>`;
* show rows where `isHidden = 0`;
* show the pictures from `announcement_images.imagePath`, relative to the
  `CITISENSE/` folder, ordered by `sortOrder`.

Report messages already use `report_messages`, which the app may already read and write.

## 6. Mascot animation

* **Login page:** the mascot fades in, floats gently, and has a soft glow and sparkles.
  It tilts slightly toward the mouse, looks away while the password is typed,
  nods when the email looks complete, and hops when you log in. It gives a small
  shake if the login fails. The Log In button shows "Logging in…" to prevent
  double clicks.
* **Dashboard:** the mascot (`css/images/mascot-walk-camera.svg`) plays a 13-second loop in
  front of a soft scene (clouds, a tree, houses, bushes): it walks in with bouncy steps, lifts a
  phone and snaps a photo with a soft flash, shows happy eyes, a little hop and a green
  "captured" check, then walks back and rests. The animation lives inside the SVG, so it works
  in a plain `<img>` tag anywhere. The earlier waving version is kept as
  `css/images/mascot-wave-animated.svg` for use elsewhere.
* **Other pages:** the mascots on Announcements, Settings and Messages
  float slowly and wave when you point at them.
* All animation is turned off for users whose device is set to reduce motion.

## Database changes

All changes **only add new tables**. No existing table or column is changed or removed,
so the mobile app keeps working.

| New table | Purpose |
|---|---|
| `announcement_images` | Pictures for each announcement (`announceID`, `imagePath`, `sortOrder`) |
| `announcement_comments` | Resident comments and barangay replies on announcements (`senderType` resident/barangay, `isHidden`) |
| `message_reads` | Records, for each barangay, which conversations have been read (drives the unread counts) |
| `duplicate_reviews` | The admin's "duplicate / not a duplicate" decisions |
| `report_image_hashes` | Cached photo fingerprints for duplicate detection |

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
