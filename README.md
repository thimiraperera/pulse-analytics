# Pulse Analytics

Website statistics for WordPress that stay on your own server. The plugin adds a Statistics page to the admin with visitors, pages, traffic sources, UTM campaigns, a world map, events, goals and a visitor log. Every report can be filtered and exported to CSV, and you can download all of them at once as a zip.

There is no outside service to sign up for. The site records its own visits and keeps them in its own database, and by default no cookies are used. The full feature list is in [pulse-analytics/readme.txt](pulse-analytics/readme.txt).

## Installing

1. Download `pulse-analytics.zip` from the [latest release](https://github.com/thimiraperera/pulse-analytics/releases/latest). GitHub also offers a "Source code" zip on every release. Don't use that one: it contains the whole repository, and WordPress won't find the plugin inside it.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip, click Install Now and then Activate.

You need WordPress 6.0 or newer and PHP 7.4 or newer.

## Updates

New versions show up on the Plugins screen, the same way updates for other plugins do. WordPress checks GitHub a few times a day. If you don't want to wait, click "Check for updates" under the plugin's name, or open Statistics > Settings > Updates. If you'd rather not click anything at all, turn on automatic updates for the plugin on the Plugins screen.

A copy installed before version 0.2.0 can't update itself. Upload the new zip once (WordPress offers to replace the installed version) and from then on updates come through WordPress.

One more thing about old copies: WordPress.org has an unrelated plugin that is also called Pulse Analytics. Since 0.2.0 the plugin tells WordPress to ignore it. On a site that still runs 0.1.0, don't accept an "update" to version 1.4 or so, because that is the other plugin.

## Releasing a new version

Push your changes to `main`. When something inside `pulse-analytics/` changed, the [release workflow](.github/workflows/release.yml) takes over. It checks the PHP and JavaScript files for syntax errors and raises the version by one step, so 0.2.0 becomes 0.2.1. It commits that as "Release 0.2.1", with your commit messages added to the changelog in `readme.txt`. Then it builds `pulse-analytics.zip` and publishes it as a GitHub release. Your sites see the new version the next time WordPress checks.

Since the workflow commits the new version number, pull before you start on the next change.

If you want to pick the number yourself, for example to go from 0.2.4 to 0.3.0, run

```
bash dev/set-version.sh 0.3.0
```

and push. If you like, write your own release notes under `== Changelog ==` in `pulse-analytics/readme.txt` as `= 0.3.0 =` with a list below it. The workflow sees a version that hasn't been released yet and publishes it as it is. You can also start a release from the Actions tab (Release, then Run workflow) and choose patch, minor or major there.

Only pushes to `main` make a release, so keep unfinished work on another branch.

## Private repository

If the repository is private, each site needs a GitHub token to see the releases:

1. On GitHub, open Settings > Developer settings > Personal access tokens > Fine-grained tokens. Create a token that can only access this repository, with Contents set to read-only.
2. Paste it on the site under Statistics > Settings > Updates, or add `define( 'PULSE_ANALYTICS_GITHUB_TOKEN', 'github_pat_...' );` to wp-config.php.

Fine-grained tokens expire, so set a reminder to replace it before the expiry date.

## Development

The plugin itself lives in `pulse-analytics/`. The `dev/` folder has test scripts and notes on how the code fits together (start with [dev/README.md](dev/README.md)). Nothing in `dev/` ends up in the release zip.

## License

GPL-2.0-or-later, like WordPress itself. See [LICENSE](LICENSE).
