#!/usr/bin/env bash
#
# Build/distribute the MetricPulse plugin after edits:
#   1. Minify assets
#   2. Sync the plugin folder into the Local (by Flywheel) site, if present  → live test site
#   3. Package metricpulse.zip and mirror it to ~/Downloads                  → WordPress.org / manual upload
#
# Safe to run repeatedly: it skips everything when no plugin SOURCE file has
# changed since the last Downloads copy. Intended for a Claude Code Stop hook.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 0

ZIP="$REPO_ROOT/metricpulse.zip"
DL="$HOME/Downloads/metricpulse.zip"

# Local (by Flywheel) plugin destination. Override with METRICPULSE_LOCAL_DEST if your
# site path differs. Sync is skipped automatically when the parent plugins dir is absent.
LOCAL_DEST="${METRICPULSE_LOCAL_DEST:-$HOME/Local Sites/test/app/public/wp-content/plugins/metricpulse}"

# Files that must never ship (dev-only). Paths are ANCHORED with a leading slash so they
# match only at the plugin root — otherwise '/vendor/' would also drop Admin/js/vendor/
# (ApexCharts), which the dashboard needs.
EXCLUDES=(
	--exclude '/tests/'
	--exclude '/phpunit.xml'
	--exclude '/vendor/'
	--exclude '.phpunit.result.cache'
	--exclude '.DS_Store'
	--exclude 'node_modules/'
)

# Skip if the Downloads copy is already newer than every plugin SOURCE file
# (minified outputs are derived, so they are excluded from the freshness check).
if [ -f "$DL" ]; then
	NEWER="$(find metricpulse -type f \
		\( -name '*.php' -o -name '*.css' -o -name '*.js' -o -name '*.txt' -o -name '*.json' -o -name '*.po' -o -name '*.mo' \) \
		! -name '*.min.*' -newer "$DL" 2>/dev/null | head -n 1)"
	if [ -z "$NEWER" ]; then
		exit 0
	fi
fi

DID_LOCAL=0
DID_ZIP=0

# Rebuild minified assets (best-effort).
python3 bin/minify.py >/dev/null 2>&1

# 1. Sync into the Local site (only if the plugins directory exists on this machine).
if [ -d "$(dirname "$LOCAL_DEST")" ]; then
	mkdir -p "$LOCAL_DEST"
	if rsync -a --delete "${EXCLUDES[@]}" metricpulse/ "$LOCAL_DEST/" 2>/dev/null; then
		DID_LOCAL=1
	fi
fi

# 2. Repackage the zip.
rm -f metricpulse/.phpunit.result.cache "$ZIP"
if zip -rq "$ZIP" metricpulse \
	-x "metricpulse/tests/*" \
	-x "metricpulse/phpunit.xml" \
	-x "metricpulse/vendor/*" \
	-x "metricpulse/.phpunit.result.cache" \
	-x "*/.DS_Store" \
	-x "*/node_modules/*"; then
	xattr -c "$ZIP" 2>/dev/null

	# Mirror to Downloads ATOMICALLY (temp file + mv) so Finder never shows a half-written file.
	TMP="$DL.tmp.$$"
	if cp "$ZIP" "$TMP" 2>/dev/null; then
		xattr -c "$TMP" 2>/dev/null
		chmod 644 "$TMP" 2>/dev/null
		mv -f "$TMP" "$DL" 2>/dev/null && DID_ZIP=1 || rm -f "$TMP"
	fi
fi

# Report what happened.
if [ "$DID_LOCAL" = 1 ] && [ "$DID_ZIP" = 1 ]; then
	MSG="MetricPulse: Local sitesine senkronlandi + zip ~/Downloads klasorune kopyalandi."
elif [ "$DID_LOCAL" = 1 ]; then
	MSG="MetricPulse: Local sitesine senkronlandi (zip paketlenemedi)."
elif [ "$DID_ZIP" = 1 ]; then
	MSG="MetricPulse: zip ~/Downloads klasorune kopyalandi (Local sitesi bulunamadi)."
else
	exit 0
fi

printf '{"systemMessage": "%s"}\n' "$MSG"
