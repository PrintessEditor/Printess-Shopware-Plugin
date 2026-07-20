#!/usr/bin/env bash
#
# Builds the PrintessShopwareIntegration plugin (Administration JS/Twig, and
# Storefront assets if the plugin has any) and clears the Shopware caches
# afterwards, so code changes made by hand show up immediately.
#
# Usage: custom/plugins/PrintessShopwareIntegration/bin/build.sh [--skip-storefront]

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
PROJECT_ROOT="$(cd -- "${PLUGIN_DIR}/../../.." && pwd)"

SKIP_STOREFRONT=0
for arg in "$@"; do
    case "$arg" in
        --skip-storefront) SKIP_STOREFRONT=1 ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Usage: $0 [--skip-storefront]" >&2
            exit 1
            ;;
    esac
done

cd "$PROJECT_ROOT"

step() {
    echo
    echo "==> $1"
}

step "Refreshing plugin list"
php bin/console plugin:refresh

step "Building Administration (Vue admin UI)"
bash bin/build-administration.sh

if [[ "$SKIP_STOREFRONT" -eq 0 && -d "${PLUGIN_DIR}/src/Resources/app/storefront/src" ]]; then
    step "Building Storefront assets"
    bash bin/build-storefront.sh
else
    step "Skipping Storefront build (no storefront assets present, or --skip-storefront given)"
fi

step "Clearing Shopware caches"
php bin/console cache:clear

step "Done. PrintessShopwareIntegration build is up to date."
