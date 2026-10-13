=== Pulse Analytics ===
Tags: analytics, statistics, stats, visitors, marketing
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
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

Each card and table has a CSV button that uses the current filters and dates. The Export menu also has "All reports in one ZIP file", with every report as its own CSV file plus the raw visits, pageviews and events. Settings > Tools has the same downloads for any period. The files are saved as UTF-8 with a BOM, so Excel shows Sinhala, Tamil and other scripts correctly.

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

1. Download pulse-analytics.zip from the latest release on GitHub: https://github.com/thimiraperera/pulse-analytics/releases/latest
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the file, click Install Now and then Activate.

Tracking starts on every page of the site as soon as the plugin is active. Administrators are not counted by default.

== Frequently Asked Questions ==

= How does it know which country a visitor is from? =

If the site runs behind Cloudflare, the country comes from the CF-IPCountry header. Other CDNs (CloudFront, Vercel, App Engine) and server GeoIP modules work the same way. Otherwise the plugin downloads the free DB-IP Lite country database (about 4 MB) after activation and updates it once a month. Regions and cities need the DB-IP City database, location headers from Cloudflare, or MaxMind GeoLite2 with a free license key.

= Does it work with caching plugins? =

Yes. The counting happens in the browser with a small script of about 5 KB (gzipped), so cached pages are counted too. Optimisation plugins that delay JavaScript until the visitor interacts with the page also delay the tracker (pulse-analytics/assets/js/tracker.js), and visitors who leave quickly are then not counted.

= My security plugin blocks the REST API. Will tracking still work? =

Yes. The script falls back to admin-ajax.php by itself.

= Do I need a cookie banner? =

In the default cookieless mode nothing is saved on the visitor's device. The persistent visitor ID mode, which gives exact new and returning visitor numbers, keeps an ID in the browser. With "Wait for consent" turned on, it only starts after the visitor accepts your cookie banner.

= How do I track a button? =

Add data-pa-event="Name of the event" to the button or link, and optionally data-pa-label="Some detail". From JavaScript you can call pulseAnalytics.track('Name', 'Detail'). To count it as a conversion, create a goal for that event.

= Will it slow down my site? =

Not noticeably. The script loads with defer and sends its data in the background. Each visit adds a few small rows to the database, and the reports are cached.

= Where is the data stored? =

In four database tables: wp_pulse_sessions, wp_pulse_pageviews, wp_pulse_pages and wp_pulse_events (with your table prefix). Deleting the plugin keeps the data unless you tick "Delete all statistics and settings when the plugin is deleted" on the Privacy tab.

= How do updates work? =

New versions are published as releases on GitHub (https://github.com/thimiraperera/pulse-analytics). WordPress checks for them along with its other updates and shows them on the Plugins screen, where automatic updates can be turned on. Statistics > Settings > Updates shows the latest version and has a button to check right away.

= Does it connect to other services? =

Only your server does, never your visitors. It downloads the geolocation database from download.db-ip.com once a month and the world map outline from cdn.jsdelivr.net once. A few times a day it asks api.github.com whether there is a new version of the plugin. On the Statistics page your own browser loads flag icons from jsDelivr and website icons from DuckDuckGo. You can turn that off under Settings > Access & display.

== Changelog ==

= 0.2.0 =
* Download every report at once: the Export menu and Settings > Tools now have a ZIP file with all reports and the raw data.
* Updates now come from GitHub releases and show up on the Plugins screen. Automatic updates can be turned on there.
* New Updates tab in the settings, with a button to check right away and a place for a token if the repository is private.

= 0.1.0 =
* First release.

== Credits ==

* IP geolocation by DB-IP (https://db-ip.com). DB-IP Lite databases are licensed under CC BY 4.0.
* World map data from Natural Earth, through the world-atlas package (ISC license).
* Flag icons from Twemoji (CC BY 4.0), through the country-flag-emoji-polyfill font.
