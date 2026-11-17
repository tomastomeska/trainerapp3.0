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
