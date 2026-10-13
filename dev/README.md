# Pulse Analytics: developer files

This folder is not part of the plugin and is not in `pulse-analytics.zip`. It holds test scripts and some notes for whoever works on the code later. You can delete it without affecting the plugin.

## Tests

Run these on a test site, not on a live one. `test-reports.php demo` replaces the sample data, and the other scripts write and then delete a few rows.

The WordPress scripts need [WP-CLI](https://wp-cli.org/) and the plugin activated on the site.

```
wp eval-file dev/tests/test-reports.php          # runs every report, breakdown and filter, lists SQL errors
wp eval-file dev/tests/test-reports.php demo     # same, after generating fresh sample data
wp eval-file dev/tests/test-units.php            # user agents, traffic sources, IP helpers, URL handling
wp eval-file dev/tests/test-flood.php            # per-visit limits of the tracking endpoint
```

The geolocation reader has its own test. `make_mmdb.py` (Python 3) builds small MaxMind-format databases in a folder of your choice. Two of them are padded to about 18 MB, so use a temp folder:

```
python dev/tests/make_mmdb.py /tmp/mmdb
php dev/tests/test-mmdb.php /tmp/mmdb
wp eval-file dev/tests/test-units.php /tmp/mmdb/test-24.mmdb   # adds the GeoIP checks
```

`test-units.php` puts your geolocation settings back the way they were when it finishes.

The updater test fakes GitHub, so it works before anything is released. Copy the fake into the test site's mu-plugins folder, build zips of a pretend newer version and run the test:

```
cp dev/tests/updater-mock.php /path/to/site/wp-content/mu-plugins/
php dev/tests/make-update-zips.php pulse-analytics /tmp/zips 90.3.0
wp eval-file dev/tests/test-updater.php /tmp/zips/release.zip
```

The fake stays switched on afterwards, so `wp plugin update pulse-analytics` installs 90.3.0 from it. Only try that on a copy of the plugin, never on a symlink to your working folder, or the update replaces your code. When you are done, delete updater-mock.php and run `wp option delete pulse_mock_github`.

## Version numbers

`set-version.sh` changes the version in the plugin header, the `PULSE_ANALYTICS_VERSION` constant and the Stable tag in readme.txt in one go:

```
bash dev/set-version.sh 0.3.0
```

The release workflow calls it too. The main README explains how releases work.

## How the plugin fits together

Tracking: `assets/js/tracker.js` posts each pageview to `POST /wp-json/pulse-analytics/v1/collect` (or to `admin-ajax.php?action=pulse_collect` if the REST API is blocked). `includes/class-collector.php` turns that into rows. Time on page and scroll depth arrive later with a signed pageview id, and clicks and form submissions come in as events.

Data lives in four tables (with the site's table prefix):

- `pulse_sessions`: one row per visit (source, campaign, location, device, entry and exit page)
- `pulse_pageviews`: one row per pageview, including the previous page for page flow reports
- `pulse_pages`: one row per URL path, with title and post id
- `pulse_events`: clicks, downloads, forms, searches, 404s and custom events

Reports: every number on the Statistics page comes from `includes/class-reports.php` through `POST /wp-json/pulse-analytics/v1/report`, which takes several widgets in one request. Results are cached in transients (1 minute when the range includes today, 6 hours otherwise). `includes/class-query.php` validates the date range and filters.

Scheduled tasks: `pulse_analytics_daily` deletes data older than the retention setting, updates the geolocation database when it is out of date and sends the email report when one is due.

Updates: the `Update URI` line in the plugin header points at the GitHub repository. Because of it WordPress does not ask WordPress.org about this plugin (which matters, since WordPress.org has a different plugin with the same slug) and calls `includes/class-updater.php` instead. The updater reads the release list from the GitHub API, keeps it for three hours and offers the highest version that has `pulse-analytics.zip` attached. The release notes fill the "View details" window. For a private repository it downloads the zip through the API with a token, then follows GitHub's redirect to the file without the token.

Filters for other code:

- `pulse_analytics_should_track` (bool): skip the tracking script on some pages
- `pulse_analytics_tracker_config` (array): change the settings passed to the tracker
- `pulse_analytics_track_hit` (bool, payload): reject a hit on the server
- `pulse_analytics_session_data` (array, payload): change a new visit before it is saved
- `pulse_analytics_geolocation` (array, IP): replace or adjust the detected location

JavaScript on the front end: `pulseAnalytics.track('Event name', 'detail')`, `pulseAnalytics.optOut()`, `pulseAnalytics.optIn()` and `pulseAnalytics.consent()`. HTML attributes `data-pa-event` and `data-pa-label` track clicks without code. Use `data-pa-form="name"` to name a form in the reports, or `data-pa-ignore` to leave it out.
