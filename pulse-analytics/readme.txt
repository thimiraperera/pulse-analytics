=== Pulse Analytics ===
Tags: analytics, statistics, stats, visitors, marketing
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Website statistics that stay on your own server: visitors, pages, traffic sources, campaigns, a world map, goals and CSV export.

== Description ==

Pulse Analytics adds a Statistics page to your WordPress admin. Visits are recorded on your own site and saved in your own database. There is no outside service to sign up for, and by default no cookies are used.

= What you can see =

The Overview tab has the main numbers for the dates you choose: visitors, visits, pageviews, views per visit, bounce rate and visit duration. Each one is compared with the previous period, or with the same dates last year if you prefer. Below the chart you get top pages, traffic sources, a world map, devices, events, goals and a heatmap of the busiest hours.

The other tabs go deeper:

* Real-time shows who is on the site right now and which pages they are reading.
* Pages lists every page with pageviews, visitors, time on page, scroll depth, entrances, bounce rate and exit rate. Click a page for its own report, including where people came from before it and where they went next.
* Sources splits traffic into channels (search, social, email, referral, paid, AI assistants like ChatGPT, direct) and lists the sites that send you visitors.
* Campaigns reports on UTM tags. The same tab has a link builder with presets for Facebook ads, newsletters, Google Ads, WhatsApp and QR codes.
* Locations has the world map. Click a country to filter everything by it. Cities show up as bubbles if you use a city database or Cloudflare location headers.
* Technology covers devices, browsers, operating systems and screen sizes.
* Events counts clicks on outbound links, file downloads, form submissions, email and phone links, internal searches and 404 errors.
* Goals turns a page visit or an event into a conversion and shows the conversion rate.
* Visitor log lists individual visits with the path each visitor took through the site.
* Content groups pageviews by post type, author and category.

Every report can be filtered. Click any row to filter by it, or use Add filter to pick a country, page, source, campaign, device, goal and so on. The date range and filters are kept in the address bar, so a filtered view can be bookmarked or sent to a colleague who has access.

= Exporting =

Each card and table has a CSV button that uses the current filters and dates. If you want everything, choose "All reports in one ZIP file" from the Export menu: you get every report as its own CSV file, plus the raw visits, pageviews and events. Settings > Tools has the same downloads for any period. The files are saved as UTF-8 with a BOM, so Excel shows Sinhala, Tamil and other scripts correctly.

= Elsewhere in the admin =

* A widget on the WordPress dashboard with the last 30 days.
* A "Views (30d)" column in the posts and pages lists, and a small statistics box in the editor.
* A view counter in the admin bar while you browse your own site.
* Weekly or monthly summary emails.
* 60 days of sample data, so you can try every report before real visits come in. It can be removed with one click.

= Privacy =

The default mode does not store anything in the visitor's browser. Visitors are counted with a hash of the IP address and browser that changes every day, and the old keys are thrown away. IP addresses are not saved unless you turn that on.

You can also respect Do Not Track, wait for consent from a cookie banner (works with the WP Consent API), delete old data automatically and put an opt-out checkbox on your privacy page with the [pulse_optout] shortcode. Bots and crawlers are filtered out.

== Installation ==

1. Download pulse-analytics.zip from the latest release on GitHub (https://github.com/thimiraperera/pulse-analytics/releases/latest). In WordPress, go to Plugins > Add New > Upload Plugin, choose the file and click Install Now. Then click Activate.
2. WordPress opens the Statistics page. Tracking is already running on every page of the site.
3. If you want to look around first, click "Explore with sample data". You can remove it later under Statistics > Settings > Tools.
4. When you have a minute, go through Statistics > Settings. The tracking mode, the excluded roles and the email reports are the ones worth checking.

Administrators are not counted by default. To see tracking working, open the site in a private window and watch the Real-time tab.

== Frequently Asked Questions ==

= How does it know which country a visitor is from? =

If the site runs behind Cloudflare, the country comes from the CF-IPCountry header. Other CDNs (CloudFront, Vercel, App Engine) and server GeoIP modules work the same way. Otherwise the plugin downloads the free DB-IP Lite country database (about 4 MB) after activation and updates it once a month. For regions and cities, switch to the DB-IP City database, turn on location headers in Cloudflare, or use MaxMind GeoLite2 with a free license key.

= Does it work with caching plugins? =

Yes. The counting happens in the browser with a small script of about 5 KB (gzipped), so cached pages are counted too. If your optimisation plugin delays JavaScript until the visitor interacts with the page, exclude pulse-analytics/assets/js/tracker.js from that setting. Otherwise you will miss visitors who leave quickly.

= My security plugin blocks the REST API. Will tracking still work? =

Yes. The script falls back to admin-ajax.php by itself.

= Do I need a cookie banner? =

In the default cookieless mode nothing is saved on the visitor's device, so in most places no consent is needed for these statistics. If you switch to the persistent visitor ID mode for exact new and returning visitor numbers, turn on "Wait for consent" and connect it to your banner. Rules differ by country, so check what applies to you.

= How do I track a button? =

Add data-pa-event="Name of the event" to the button or link, and optionally data-pa-label="Some detail". From JavaScript you can call pulseAnalytics.track('Name', 'Detail'). To count it as a conversion, create a goal for that event.

= Will it slow down my site? =

Not noticeably. The script loads with defer and sends its data in the background. Each visit adds a few small rows to the database, and the reports are cached.

= Where is the data stored? =

In four database tables: wp_pulse_sessions, wp_pulse_pageviews, wp_pulse_pages and wp_pulse_events (with your table prefix). Deleting the plugin keeps the data unless you tick "Delete all statistics and settings when the plugin is deleted" on the Privacy tab.

= How do updates work? =

The plugin is not on WordPress.org. New versions come from the releases of its GitHub repository, https://github.com/thimiraperera/pulse-analytics. WordPress checks for them along with its other updates, and they show up on the Plugins screen like any other plugin update. You can turn on automatic updates for this plugin there. To check right away, use the "Check for updates" link under the plugin name or go to Statistics > Settings > Updates.

= Does it connect to other services? =

Only your server does, never your visitors. It downloads the geolocation database from download.db-ip.com once a month and the world map outline from cdn.jsdelivr.net once. A few times a day it asks api.github.com whether there is a new version of the plugin. On the Statistics page your own browser loads flag icons from jsDelivr and website icons from DuckDuckGo. You can turn that off under Settings > Access & display.

== Changelog ==

= 0.1.0 =
* First release.

== Credits ==

* IP geolocation by DB-IP (https://db-ip.com). DB-IP Lite databases are licensed under CC BY 4.0.
* World map data from Natural Earth, through the world-atlas package (ISC license).
* Flag icons from Twemoji (CC BY 4.0), through the country-flag-emoji-polyfill font.
