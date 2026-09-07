#!/bin/sh

PLUGIN_SLUG="everest-forms"
PROJECT_PATH=$(pwd)
BUILD_PATH="${PROJECT_PATH}/build"
DEST_PATH="$BUILD_PATH/$PLUGIN_SLUG"

echo "Generating build directory..."
rm -rf "$BUILD_PATH"
mkdir -p "$DEST_PATH"

echo "Installing PHP and JS dependencies..."
npm install --legacy-peer-deps || exit "$?"
# --ignore-platform-reqs: composer.lock has dev-only packages (phpunit and
# friends) locked to versions requiring old PHP, while the actual production
# dependencies need a newer PHP -- no single installed PHP version can ever
# satisfy both at once. Composer validates the whole lock file's platform
# requirements up front regardless of which packages are actually going to
# be installed, so this is needed on both calls below, not just the --no-dev
# one, even though the first call still installs the dev packages that
# trigger the conflict.
composer install --ignore-platform-reqs || exit "$?"
echo "Running JS Build..."
npm run build:core || exit "$?"
echo "Cleaning up PHP dependencies..."
composer install --no-dev --ignore-platform-reqs || exit "$?"

echo "Syncing files..."
rsync -rc --exclude-from="$PROJECT_PATH/.distignore" "$PROJECT_PATH/" "$DEST_PATH/" --delete --delete-excluded

echo "Generating zip file..."
cd "$BUILD_PATH" || exit
zip -q -r "${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG/"

cd "$PROJECT_PATH" || exit
mv "$BUILD_PATH/${PLUGIN_SLUG}.zip" "$PROJECT_PATH"
echo "${PLUGIN_SLUG}.zip file generated!"

echo "Build done!"
