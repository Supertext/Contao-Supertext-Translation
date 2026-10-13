#!/usr/bin/env bash
# Copies the Supertext PHP SDK into lib/supertext-sdk (namespace Supertext\Sdk, autoloaded
# through composer.json). Source: https://github.com/Supertext/Supertext-API-SDK-and-Guide
# (sdk/php).
#
#   scripts/sync-sdk.sh            # latest main
#   scripts/sync-sdk.sh v0.2.0     # a tag or commit
#   SDK_DIR=../Supertext-API-SDK-and-Guide scripts/sync-sdk.sh   # a local checkout
#
# Until the SDK is published on Packagist, the plugin ships this copy; then it becomes a
# normal Composer dependency ("supertext/supertext-sdk") and this script goes away.
set -euo pipefail
cd "$(dirname "$0")/.."
ref="${1:-main}"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
if [ -n "${SDK_DIR:-}" ]; then
  src="$(cd "$SDK_DIR" && pwd)"
else
  git clone -q https://github.com/Supertext/Supertext-API-SDK-and-Guide "$work/sdk"
  git -C "$work/sdk" checkout -q "$ref"
  src="$work/sdk"
fi
commit="$(git -C "$src" rev-parse --short HEAD)"
# All SDKs share one version; the TypeScript package.json carries it.
version="$(sed -n 's/^  "version": "\(.*\)",$/\1/p' "$src/sdk/typescript/package.json" | head -1)"
rm -rf lib/supertext-sdk
mkdir -p lib/supertext-sdk
cp -R "$src/sdk/php/src" lib/supertext-sdk/src
cp "$src/sdk/php/LICENSE" lib/supertext-sdk/LICENSE
printf 'Supertext SDK for PHP %s, commit %s\nhttps://github.com/Supertext/Supertext-API-SDK-and-Guide/tree/%s/sdk/php\nCopied by scripts/sync-sdk.sh. Do not edit: change the SDK repository and sync again.\n' "$version" "$commit" "$commit" > lib/supertext-sdk/SOURCE
echo "lib/supertext-sdk: Supertext PHP SDK $version ($commit)"
