#!/usr/bin/env bash
# Decides the version for the release workflow and writes the release notes.
# An unreleased version in the code is released as it is. Otherwise, if the plugin
# changed since the last release, the version goes up by BUMP (patch by default).
set -euo pipefail

plugin=pulse-analytics
main="$plugin/pulse-analytics.php"
readme="$plugin/readme.txt"
output=${GITHUB_OUTPUT:-/dev/stdout}
notes_file=${NOTES_FILE:-notes.md}

header=$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' "$main" | head -n 1)
constant=$(sed -n "s/^define( 'PULSE_ANALYTICS_VERSION', '\([0-9.]*\)' );.*/\1/p" "$main" | head -n 1)
stable=$(sed -n 's/^Stable tag: *\([0-9.]*\).*/\1/p' "$readme" | head -n 1)

if [ -z "$header" ] || [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "::error::The version numbers do not match (plugin header: '$header', PULSE_ANALYTICS_VERSION: '$constant', readme Stable tag: '$stable'). Run 'bash .github/scripts/set-version.sh x.y.z' to set all three."
	exit 1
fi

version=$header
last_tag=$(git tag --list 'v[0-9]*' --sort=-v:refname | head -n 1)
bumped=false

if ! git rev-parse -q --verify "refs/tags/v$version" > /dev/null; then
	if [ -n "$last_tag" ] && [ "$(printf '%s\n%s\n' "${last_tag#v}" "$version" | sort -V | tail -n 1)" != "$version" ]; then
		echo "::error::Version $version is lower than the last release ($last_tag). Set a higher version with 'bash .github/scripts/set-version.sh x.y.z'."
		exit 1
	fi
	echo "Version $version has not been released yet, so it will be released as it is."
elif [ -z "${BUMP:-}" ] && git diff --quiet "v$version" HEAD -- "$plugin"; then
	echo "Nothing in $plugin/ changed since v$version. No release needed."
	echo "release=false" >> "$output"
	exit 0
else
	IFS=. read -r major minor patch <<< "$version"
	case "${BUMP:-patch}" in
		major) version="$((major + 1)).0.0" ;;
		minor) version="$major.$((minor + 1)).0" ;;
		*) version="$major.$minor.$((patch + 1))" ;;
	esac
	bumped=true
	echo "Raising the version to $version."
fi

# Release notes: the changelog entry in readme.txt when there is one,
# otherwise the messages of the commits that changed the plugin.
notes=$(awk -v heading="= $version =" '
	$0 == heading { found = 1; next }
	found && (/^= / || /^== /) { exit }
	found && NF { print }
' "$readme")
if [ -z "$notes" ]; then
	range=HEAD
	if [ -n "$last_tag" ]; then
		range="$last_tag..HEAD"
	fi
	notes=$(git log --no-merges --format='* %s' "$range" -- "$plugin" | grep -v -E '^\* Release [0-9]' || true)
fi
if [ -z "$notes" ]; then
	notes="* Small fixes and updates."
fi
printf '%s\n' "$notes" > "$notes_file"

if [ "$bumped" = true ]; then
	bash .github/scripts/set-version.sh "$version"
	awk -v heading="= $version =" -v notes="$notes_file" '
		{ print }
		/^== Changelog ==/ && !done {
			print ""
			print heading
			while ((getline line < notes) > 0) print line
			done = 1
		}
	' "$readme" > "$readme.new"
	mv "$readme.new" "$readme"
fi

{
	echo "release=true"
	echo "version=$version"
	echo "bumped=$bumped"
} >> "$output"
