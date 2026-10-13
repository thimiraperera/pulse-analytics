#!/usr/bin/env bash
# Sets the version in the plugin header, the PULSE_ANALYTICS_VERSION constant
# and the Stable tag in readme.txt.
#
#   bash .github/scripts/set-version.sh 0.3.0
set -euo pipefail

version=${1:-}
if ! [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: bash .github/scripts/set-version.sh 1.2.3" >&2
	exit 1
fi

root=$(cd "$(dirname "$0")/../.." && pwd)
main="$root/pulse-analytics/pulse-analytics.php"
readme="$root/pulse-analytics/readme.txt"

sed -i -E "s/^( \* Version: +)[0-9.]+/\1$version/" "$main"
sed -i -E "s/^(define\( 'PULSE_ANALYTICS_VERSION', ')[0-9.]+(' \);)/\1$version\2/" "$main"
sed -i -E "s/^(Stable tag: *)[0-9.]+/\1$version/" "$readme"

grep -q -E "^ \* Version: +$version\$" "$main" &&
	grep -q "define( 'PULSE_ANALYTICS_VERSION', '$version' );" "$main" &&
	grep -q -E "^Stable tag: *$version\$" "$readme" || {
	echo "Could not update every version number. Check $main and $readme." >&2
	exit 1
}
echo "Version set to $version."
