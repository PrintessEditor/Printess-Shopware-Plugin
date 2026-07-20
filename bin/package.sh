#!/usr/bin/env bash
#
# Packages the PrintessShopwareIntegration plugin into a zip that can be uploaded via Shopware's
# admin "Upload extension" button (Extensions > My extensions), rather than requiring shell/composer
# access on the target shop.
#
# Rebuilds the Administration and Storefront assets first by default, so the compiled dist/public
# bundles shipped in the zip are always current - pass --skip-build to reuse whatever is already
# built (e.g. if you just ran bin/build-storefront.sh yourself).
#
# Usage: custom/plugins/PrintessShopwareIntegration/bin/package.sh [--skip-build]

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
PROJECT_ROOT="$(cd -- "${PLUGIN_DIR}/../../.." && pwd)"
PLUGIN_NAME="PrintessShopwareIntegration"
# Lives inside the plugin directory itself (not the Shopware project root) since this plugin's repo
# on GitHub is just this directory - anything outside it wouldn't be there for a fresh checkout.
BUILD_DIR="${PLUGIN_DIR}/build"

SKIP_BUILD=0
for arg in "$@"; do
    case "$arg" in
        --skip-build) SKIP_BUILD=1 ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Usage: $0 [--skip-build]" >&2
            exit 1
            ;;
    esac
done

step() {
    echo
    echo "==> $1"
}

VERSION="$(php -r '
    $composer = json_decode(file_get_contents($argv[1]), true);
    echo $composer["version"] ?? "0.0.0";
' "${PLUGIN_DIR}/composer.json")"

cd "$PROJECT_ROOT"

if [[ "$SKIP_BUILD" -eq 0 ]]; then
    step "Refreshing plugin list"
    php bin/console plugin:refresh

    step "Building Administration (Vue admin UI)"
    bash bin/build-administration.sh

    step "Building Storefront assets"
    bash bin/build-storefront.sh
else
    step "Skipping asset build (--skip-build given) - make sure dist/ and Resources/public/administration are already up to date"
fi

step "Packaging ${PLUGIN_NAME} v${VERSION}"

# Staged under a directory literally named "PrintessShopwareIntegration" - Shopware's extension
# upload requires the zip's single top-level folder to match the plugin's technical name exactly.
WORK_DIR="$(mktemp -d)"
STAGE_DIR="${WORK_DIR}/${PLUGIN_NAME}"
mkdir -p "$STAGE_DIR"

rsync -a \
    --exclude='bin/' \
    --exclude='build/' \
    --exclude='src/printess-integration-skills/' \
    --exclude='PROMPTS.txt' \
    --exclude='.DS_Store' \
    --exclude='*.swp' \
    "${PLUGIN_DIR}/" "${STAGE_DIR}/"
# bin/ is this dev checkout's own build/package helper scripts, not part of the shipped plugin.
# build/ is this script's own output directory - excluded so a previous zip never ends up nested
# inside the next one. src/printess-integration-skills/ holds Claude Code skill specs for developing
# this integration, and PROMPTS.txt is the dev prompt log kept for reproducing this build - neither
# is anything the customer's shop needs at runtime.

mkdir -p "$BUILD_DIR"
ZIP_PATH="${BUILD_DIR}/${PLUGIN_NAME}-${VERSION}.zip"
rm -f "$ZIP_PATH"

(cd "$WORK_DIR" && zip -rq "$ZIP_PATH" "$PLUGIN_NAME")

rm -rf "$WORK_DIR"

step "Done."
echo "Zip ready at: ${ZIP_PATH}"
echo "Upload it in the Shopware admin under Extensions > My extensions > Upload extension."
