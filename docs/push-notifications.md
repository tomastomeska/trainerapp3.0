# Push notifications deployment

Push notification changes are deployed in separate stages. Back up the affected
PHP files before replacing them over FTP. Do not copy production credentials or
database exports into the repository.

## Stage 2: PWA shell

The PWA manifest and Service Worker are served from the application root.
The deployment includes `manifest.webmanifest`, `sw.js`,
`assets/js/pwa.js`, the three files linking those resources, and
`assets/pwa/icon-{180,192,512}.png`. Confirm that the manifest, worker, and
icons return successfully over HTTPS and that the browser reports the worker
as activated for the application origin.

On iOS/iPadOS, web push requires a supported OS version and the user must add
the site to the Home Screen. A running Service Worker alone does not enable
push delivery.

## Stage 3: device registration backend

1. Back up the production database.
2. Select the application database in phpMyAdmin and import the separately
   supplied Stage 3 SQL migration. It creates only three new tables and does
   not change existing tables or user records.
3. If any statement fails, stop and inspect the error before uploading PHP
   files. Do not run a rollback that drops tables if they may contain data.
4. Upload `api/push_subscriptions.php` and `assets/js/push-subscriptions.js`
   to their matching paths. The JavaScript helper is intentionally not loaded
   or exposed in the user interface at this stage.
5. Registration requires an authenticated coach or athlete session and a
   valid CSRF token. Browser permission is requested only when the future
   settings UI explicitly calls `subscribe()` from a user gesture.

The API supports creating/updating a subscription, listing the current user's
active devices, revoking one of their devices, disabling all of their devices,
and reading/updating their category preferences. Each account is associated
with either a coach ID or athlete ID; users cannot act on another account's
subscriptions.

Stage 3 does not send notifications. Sending requires Stage 4 (VAPID
configuration and the PHP Web Push library), and users will not be able to
activate subscriptions until the settings interface is implemented.

The deployment environment and device registration must be checked on the
production site using a test account before beginning Stage 4.

## Stage 4a: Web Push dependencies and VAPID setup

The Web Push dependencies are isolated under `dependencies/webpush/vendor` and
must be uploaded in full, including `vendor/autoload.php`. Do not replace the
existing root `vendor` directory: it contains the application's PHPMailer.
Composer is used only to prepare this directory, never at runtime.

Upload `admin/push_setup.php`, sign in as the superadministrator over HTTPS,
and visit that page to create VAPID keys. The key pair is stored as a single
value in `app_settings` under `push_vapid_keys`; the private key is not shown
in the page or sent to the browser. Restrict access to database backups and
administrative credentials accordingly. The setup page does not rotate existing
keys, since doing so would invalidate registered push subscriptions.

Back up the database before setup. To disable or roll back this stage, remove
the setup page and the isolated Web Push dependency directory from the server.
Keep the existing key pair in the database; do not delete it or regenerate it
without a separate subscription migration plan. This stage does not send push
notifications or modify the existing application flows.

## Stage 4b: subscription endpoint validation

Back up the deployed `api/push_subscriptions.php`. Upload
`includes/push_endpoint.php` before replacing `api/push_subscriptions.php`.
Check endpoint validation on the production server before enabling a sender.

New subscriptions are accepted only from Firebase Cloud Messaging, Mozilla's
push service, Apple push subdomains, or Microsoft WNS subdomains, over HTTPS
on the default port. Other providers will need to be reviewed before they can
be supported. Previously stored subscriptions are not modified; the sender
must independently validate every stored endpoint and must not follow redirects.

To roll back, restore the backed-up API file first, then remove the new helper.
Do not use a sender while endpoint validation is absent. No SQL changes are
required.

## Stage 4c: delivery queue and worker

Back up the production database first. Check that `push_delivery_queue` does
not already exist, then import `sql/push_delivery.sql` in phpMyAdmin. Do not
repeat the Stage 3 migration. The new table stores the generic notification
body until it is processed; do not put sensitive information in push payloads.

After the import, upload `includes/push_notifications.php`,
`cron_push_notifications.php`, `admin/push_cron_setup.php`, and the temporary
`admin/push_delivery_check.php`. Existing Stage 4a dependencies and the Stage
4b endpoint helper are required. Open the temporary check page as a
superadministrator and verify its results. Delete that page after verification.
No application event is connected to this module yet.

The worker checks the subscription owner, account status, category preference
and endpoint again before sending, forbids redirects, and uses `aes128gcm`.
Expired subscriptions are marked invalid, not deleted. A successful provider
response means acceptance by the push service, not confirmed display on a
device. Failed transient attempts are retried up to five times with a minimum
five-minute delay. A crash or timeout after a provider accepted a request can
cause a retry; the stable notification tag reduces duplicate displays but
cannot guarantee exactly-once delivery.

After a source event has been written, integrations may call
`pushEnqueueNotification` using a stable event key and the recipient's role and
ID. The enqueue can participate in the event transaction via a savepoint;
the event must commit before `pushDispatchAfterResponse` is scheduled. Callers
must catch and log enqueue errors without disrupting an already saved event.
Immediate dispatch is attempted only when `fastcgi_finish_request` is
available; its actual behavior still needs testing on the hosting server.
Without that function, delivery waits for the scheduled worker.

Bulk messages sent by an administrator or coach to athletes enqueue a generic
notification for each recipient after the message is committed. They use the
messages preference and open the athlete's inbox; existing messages are not
notified retroactively.

The CRON setup page creates a separate secret after an authenticated CSRF-
protected action and displays the URL only to the administrator. Do not share
the URL: the WEDOS service requires the secret in a GET parameter, which may
appear in server, proxy, browser, or hosting logs. Restrict access to those
logs and to database backups. Configure or activate CRON only after approval.
WEDOS CRON+ supports a five-minute minimum interval but does not guarantee
exact execution times.

To roll back, pause the push CRON if it has been configured, then remove the
new worker and setup/check pages. Existing application events are not affected.
Keep the queue and log tables and their data; do not drop them during rollback.
The Web Push dependencies and VAPID keys should also be retained.

## Stage 5: test notification

Temporarily upload `push_notification_test.php` to the application root. Sign
in with a test coach or athlete account and open it on a test device. Activate
the device, then send a test. The notification is queued for all active
devices on that account; the messages category must be enabled. Sending is
limited to one test per account per five-minute interval.

The page refuses to take over an existing browser subscription belonging to
another account or tied to a different or unverifiable VAPID key. It reports
provider acceptance separately from actual display: confirm that the operating
system displayed the notification and that clicking it opens the test page with
its confirmation message. Old test notifications targeting that temporary page
will no longer open it once the page is removed. If the queue remains pending,
check immediate-dispatch behavior and the scheduled worker.
On iOS, web push is available only in the installed Home Screen web app.

After verification, optionally remove the test device using the page, then
delete the temporary page both locally and on the server. Do not remove queue
or log records as part of this cleanup.

## Stage 6: profile notification settings

The shared profile notification card includes an expandable illustrated
installation and notification guide for iPhone/iPad, Android and computers.
Deploy `includes/push_install_guide.php` together with
`includes/push_settings.php`; both coach and athlete profiles use it. The
illustrations are schematic because browser and system menus can vary.

Back up the deployed `api/push_subscriptions.php`,
`assets/js/push-subscriptions.js`, `profile.php`, and
`athlete_profile_edit.php`. Upload the shared `includes/push_settings.php`
and `assets/js/push-settings.js` first, then the updated API, subscription
helper and profile pages. No SQL change is required. Upload the cleaned
`includes/athlete_header.php` and remove the temporary Stage 5 test page from
the server after testing is complete.

The coach profile and the athlete profile-edit page show registered devices
and category switches. Device and preference management remains available
when the current browser cannot receive push. Activation requests permission
only after a user action; the interface does not call a device active without
browser permission. Replacing a browser subscription or associating it with a
different account requires explicit confirmation. Removing a remote device
revokes it on the server; its browser subscription cannot be removed remotely.

Test settings with an appropriate test account, and restore any preferences
changed solely for testing. A category switch prepares a preference; it does
not imply that every listed event type is already connected. To roll back,
restore the backed-up profile pages, API and JavaScript helper. Retain the
database tables, subscriptions, preferences, and logs.

## Stage 7a: coach-athlete chat notifications

Back up the seven existing chat files listed below. Upload the new
`includes/push_chat_notifications.php` first, followed by:

- `api/coach_athlete_chat.php`
- `api/athlete_coach_chat.php`
- `athlete_chat.php`
- `coach_chat_mobile.php`
- `athlete_chat_mobile.php`
- `api/mobile/chat.php`
- `api/mobile/athlete_portal.php`

The Stage 4 sender and Stage 6 subscription helper are prerequisites. No SQL
changes are needed. A saved coach-athlete message queues a generic notification
for the other party, independent of email notification settings. The message
body is never included. Registered devices and the messages category are
checked by the existing queue; the message ID identifies the event.

Test both directions using test accounts and activated devices, including the
installed iPhone app with the application closed. Check that clicking opens the
correct chat and that turning off the messages category prevents push for the
recipient; restore the setting afterwards. Also check the mobile athlete chat
while open: its older polling alert is suppressed for coach messages when the
current device has push or the category is disabled. Browser and OS settings
may still affect display.

Immediate processing is best effort. Until the separately approved CRON job is
actually configured, queued failures will not automatically retry on schedule.
Admin chats, calendar events, online training and reminders are not connected
by this stage.

To roll back, restore the seven backed-up chat files before removing the new
helper. Keep the queue, logs, subscriptions and settings. Previously queued
notifications can still be sent by a running worker; pause it if necessary and
do not delete queued data without a separate decision.

### Calendar notification integration

Back up `api/athlete_calendar_save_event.php`,
`api/athlete_calendar_delete_event.php`, `api/calendar_save_event.php`,
`api/calendar_delete_event.php`, `api/calendar_approve_event.php`,
`api/mobile/athlete_portal.php`, and `api/mobile/calendar.php`. Upload
`includes/push_calendar_notifications.php` first, then those seven API files.
No SQL changes are required.

Requests, changed requests and athlete cancellations notify the coach.
Coach-created, changed, approved, rejected and cancelled events notify the
affected athletes. Both participants of a paired event are considered, and
one bulk series action queues at most one notification per recipient and device.
Deleting an event from its detail also sends push without changing the existing
payment or replacement behavior. Calendar preferences and registered devices
are checked by the existing queue. Coach-facing notifications include the
athlete's name. Calendar notifications include the date, time and location,
or explicitly say when these are unavailable. For series actions, the date
and location describe the first affected event for that recipient. This
information can appear on a locked screen and is stored in the delivery queue,
including after delivery; it is also sent to the device's push provider.
New coach and athlete notifications open the calendar at the relevant date
where available and focus an accessible event when it still exists. Cancelled
events open the relevant week without attempting to open a deleted event.
Notifications queued before this change retain their original target.

Verify create, approval, change, rejection, cancellation and paired or
recurring events using test accounts, with the calendar category both enabled
and disabled. Confirm click navigation in the installed iPhone app as well as
on the coach's device. The icon badge still counts unread chat messages, not
calendar events. Immediate dispatch is best effort; queued failures have no
scheduled retries until CRON is configured separately. Online training and
reminders are not connected by this stage.

To roll back, restore the seven API files before removing the calendar helper.
Keep subscriptions, preferences, queues and logs. Already queued notifications
may still be sent by a worker; do not delete queue data without a decision.

For the calendar refresh and athlete click-navigation update, back up and
upload `athlete_calendar.php`, `calendar.php`, and
`includes/push_calendar_notifications.php`. No SQL is required. Test fresh
notifications for events in another week, cancellation from the athlete detail
and both backgrounded and closed installed PWAs. Calendars request fresh data
when returning to the foreground or receiving a notification-click message.
A failed auxiliary month or training list is reported in that list,
independently of the week view. Restore the three backups to roll back.

For the coach calendar refresh warning update, back up and upload
`calendar.php`; no SQL is required. An automatic weekly refresh failure now
shows a non-blocking warning with a retry button, while a monthly-list failure
has its own warning and retry. Previously displayed data may be outdated.
Test foregrounding and notification clicks, connection loss and recovery,
and month-list retry. If a failure persists, capture the browser console error
(`Obnovení kalendáře selhalo` or `Načtení měsíčního seznamu selhalo`);
the warning alone does not identify its cause. Restore the backup to roll back.

For the athlete calendar refresh update, back up and upload
`athlete_calendar.php`; no SQL is required. Automatic refreshes retry once
after a failure and ignore obsolete request failures. A remaining failure is
shown with details and a retry button, while the last displayed week remains
visible when the data request fails. Check notification clicks, the event
detail, returning to the app and connection loss/recovery on an athlete device.
Console errors are logged
as `Obnovení kalendáře sportovce selhalo`. Restore the backup to roll back.

For the calendar scrolling fix, back up and upload `athlete_calendar.php` and
`calendar.php`; no SQL is required. Both calendars use a non-scrolling
DayPilot grid and no longer call `scrollToHour`. Test loading the current week
and opening an athlete event from a new notification. Restore both backups to
roll back.

### Online training notifications

Back up `includes/online_training.php`, `online_training_detail.php`,
`api/online_training_complete.php`, and `api/online_training_save_coach_note.php`.
Upload `includes/push_online_training_notifications.php` first, then the four
updated files. No SQL change is required.

Sending an online training notifies the athlete; completion notifies the coach
with the athlete's name. A new or changed visible coach note or reaction also
notifies the athlete. Messages identify the training number but do not include
the note, results or exercise details. A set and its series are prepared in an
invisible draft, so they do not generate separate pushes before sending.

Test sending, completing and reacting with test accounts, both with the online
training preference enabled and disabled, including click navigation. Push
subscriptions do not keep a user logged in. To roll back, restore the four
backed-up files before removing the helper. Retain all queue and account data.

### Notification click navigation on iOS

Back up and upload `sw.js`, `assets/js/pwa.js`, `includes/athlete_header.php`,
`includes/header.php`, `login.php`, `athlete_chat_mobile.php` and
`coach_chat_mobile.php`. No SQL is required. The worker stores the same-origin
target of a notification click briefly in IndexedDB so the app can recover it
if iOS opens the start page instead. The target is consumed once and expires
after two minutes; the existing direct navigation remains the fallback if
storage fails. This does not extend the user's login session.

Reopen the installed app so the updated scripts and worker can activate, then
test fresh notifications with the app both in the background and closed.
Verify both account roles and the opened chat. Restoring those seven backed-up
files rolls back the change; the worker's temporary navigation record expires
without deleting any existing application data.

### Unread-message badge

Back up `api/push_subscriptions.php`, `includes/push_notifications.php`,
`sw.js`, `assets/js/pwa.js`, `includes/athlete_header.php`,
`includes/header.php`, `login.php`, `athlete_chat_mobile.php`, and
`coach_chat_mobile.php`, as well as `api/athlete_coach_chat.php`,
`api/athlete_chat_admin.php`, and `includes/support_widget.php`. Upload the
new `includes/push_unread.php` first, then the updated APIs and sender before
the other files. The preceding notification-click fix is included in these
files. No SQL change is required.

On supported installed PWAs, the badge shows unread coach-athlete and
administrator chat messages. The server counts only the authenticated
recipient's messages; a push carries a numeric snapshot, and an open app
refreshes the count on startup, return to the foreground, and periodically
while visible. A zero count clears the badge. Push notification display does
not depend on the badge succeeding. Background chat polling avoids marking
messages as read until the conversation is visible again.

The badge is a best-effort snapshot, not an always-live cross-device count:
out-of-order pushes, an expired session or reading messages on another device
can leave it stale until the next successful update. On iOS/iPadOS the app must
be installed on the Home Screen, notifications must be allowed, and system
badge display must be enabled. Android may show a system-managed dot rather
than the numeric count. Other notification categories are not counted yet.

The quick-actions button shows the same unread-chat total while its actions are
collapsed. Athlete-to-coach chat pushes include the athlete's name, which can
appear on a lock screen and is retained in the delivery queue. Back up and
upload `includes/support_widget.php` and `includes/push_chat_notifications.php`
for these changes; no SQL is required. Restore those files to roll back.

Test a fresh notification with the installed app closed, then open the chat
and check that the badge clears after the unread messages are read. Check the
count with two unread messages and with both roles. Restore the backed-up files
to roll back; do not delete subscriptions, logs or other application data.

### Surveys, information channel and gallery

Back up the database and affected files. Run `sql/push_content_categories.sql`
in phpMyAdmin before deploying the PHP changes; it extends the two existing
category enums without removing old values. Stop if either statement fails.
Upload `includes/push_content_notifications.php` before replacing
`includes/push_notifications.php`, followed by `api/push_subscriptions.php`,
`includes/push_settings.php`, `includes/survey_page.php`, `admin/surveys.php`,
`admin/infokanal.php`, `admin/gallery.php`, `gallery_upload.php`, `gallery.php`
and `athlete_gallery.php`. Do not replace the existing push subscriptions.

Polls and questionnaires notify their selected audience when published.
Information articles notify newly eligible audiences when published immediately;
future scheduled articles are deliberately not notified automatically.
Admin gallery uploads notify their selected audience once per upload, and
coach gallery files notify their selected athletes once per file. Private
files are silent. Titles or filenames appear on lock screens and in the queue.
Each category has a separate setting, enabled by default. Existing permissions
are checked again before queued delivery.

The existing push CRON must be configured for queued batches and retries.
Upload `cron_push_notifications.php` and `admin/push_cron_setup.php` if absent.
Before enabling it, inspect pending queue counts and ages; it may deliver old
queued notifications. Decide how to handle any backlog before starting CRON.
As superadministrator, open the setup page over HTTPS and copy the generated
secret URL directly to WEDOS CRON; do not share it. Use the shortest available
interval, all hours and days. Each run processes a bounded batch, so a large
queue may need multiple runs. A longer hosting interval also delays retries.
CRON delivers already queued pushes; it does not publish scheduled articles.

Test each audience, disabled settings, direct links, hidden/private items,
multiple files and devices, and a queue larger than the immediate batch. If
rolling back, allow new-category queued deliveries to finish under the new
worker first; then pause CRON and restore the backed-up PHP files. Keep the
new helper, expanded enums, subscriptions, preferences, logs and queue data.
Do not run an old worker against pending new-category items or shrink enums
without a separate data migration.
