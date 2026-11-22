#!/usr/bin/env zsh
set -e

mkdir -p dist
STAMP=$(date +%y%j-%H%M)
TMP_DIR=$(mktemp -d)
STAGE="$TMP_DIR/AV-TouchPanel-TechKit"
mkdir -p "$STAGE"

# Copy full project with dereferenced symlinks (-L) so vendor/silverstripe/*/client and public/_resources are complete
rsync -aL \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='silverstripe-cache*' \
  --exclude='public/assets/*' \
  --exclude='.env' \
  --exclude='docker-compose.override.yml' \
  --exclude='dist' \
  --exclude='patches' \
  --exclude='.DS_Store' \
  ./ "$STAGE/"

mkdir -p "$STAGE/public/assets"

# Build clean Linux/macOS .tar.gz (no macOS xattrs) and Windows .zip
COPYFILE_DISABLE=1 tar --no-xattrs -czf "dist/AV-TouchPanel-TechKit-${STAMP}.tar.gz" -C "$TMP_DIR" AV-TouchPanel-TechKit
(cd "$TMP_DIR" && zip -rq "$OLDPWD/dist/AV-TouchPanel-TechKit-${STAMP}.zip" AV-TouchPanel-TechKit)
rm -rf "$TMP_DIR"

echo "Built complete TechKit packages:"
ls -lh "dist/AV-TouchPanel-TechKit-${STAMP}.tar.gz" "dist/AV-TouchPanel-TechKit-${STAMP}.zip"
