#!/bin/sh
# Copies the PHP Supertext SDK from its GitHub repository into lib/supertext-sdk, with its
# namespace changed from Supertext\Sdk to Supertext\ContaoTranslation\Sdk. The bundle ships
# this copy, so sites install nothing besides the bundle, and another package that requires
# supertext/supertext-sdk itself can never clash with it.
#
#   scripts/vendor-sdk.sh [commit-or-branch]   (default: main)
#   SDK_DIR=/path/to/clone scripts/vendor-sdk.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${SDK_DIR:-}" ]; then
    SRC="$SDK_DIR"
else
    git clone --quiet https://github.com/Supertext/Supertext-API-SDK-and-Guide "$TMP/sdk"
    git -C "$TMP/sdk" checkout --quiet "$REF"
    SRC="$TMP/sdk"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"

DEST="$ROOT/lib/supertext-sdk"
rm -rf "$DEST"
mkdir -p "$DEST"
# src/ has the version-neutral classes (Supertext, Http/) and one folder per API version (V1/).
cp -R "$SRC/sdk/php/src" "$DEST/src"
cp "$SRC/LICENSE" "$DEST/LICENSE"
# Namespaces and use statements (Supertext\Sdk\V1\…), plus class names inside PHP strings (Supertext\\Sdk\\…).
find "$DEST/src" -name '*.php' -exec sed -i 's/Supertext\\Sdk\\/Supertext\\ContaoTranslation\\Sdk\\/g; s/Supertext\\Sdk;/Supertext\\ContaoTranslation\\Sdk;/g; s/Supertext\\\\Sdk\\\\/Supertext\\\\ContaoTranslation\\\\Sdk\\\\/g' {} +
VERSION="$(sed -n "s/.*SDK_VERSION = '\([^']*\)'.*/\1/p" "$DEST/src/Supertext.php")"
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/README.md" <<EOF
# Supertext SDK (bundled copy)

PHP part of the [Supertext SDK](https://github.com/Supertext/Supertext-API-SDK-and-Guide),
version $VERSION, commit \`$COMMIT\`, namespace \`Supertext\\ContaoTranslation\\Sdk\` instead of \`Supertext\\Sdk\`.

Don't edit these files: fix the SDK in its own repository, then run \`scripts/vendor-sdk.sh\`.
EOF
if grep -rq 'Supertext\\Sdk' "$DEST/src" || grep -rqF 'Supertext\\Sdk' "$DEST/src"; then
    echo "vendor-sdk: unprefixed Supertext\\Sdk reference left in $DEST/src" >&2
    exit 1
fi
echo "lib/supertext-sdk: Supertext SDK (PHP) $VERSION from commit $COMMIT"
