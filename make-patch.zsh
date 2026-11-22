#!/usr/bin/env zsh
set -e

# Parse optional -v / --version flag
zmodload zsh/zutil
zparseopts -D -E v:=opt_version -version:=opt_version

BASE_VERSION="${opt_version[2]:-1.0}"
JULIAN_SUFFIX=$(date +%y%j-%H%M%S)
VERSION="${BASE_VERSION}-${JULIAN_SUFFIX}"

# Exclude system/non-module top-level folders when auto-detecting available modules
IGNORE_DIRS=("app" "public" "vendor" "silverstripe-cache" ".git" ".github" ".ddev" ".vscode" "node_modules" "patches")

# Discover top-level SilverStripe module folders automatically (must contain _config.php)
AVAILABLE_MODULES=()
for d in *(/); do
  if (( ! ${IGNORE_DIRS[(Ie)$d]} )) && [[ -f "$d/_config.php" ]]; then
    AVAILABLE_MODULES+=("$d")
  fi
done

# Remaining positional arguments are module names (or 'all' / 'none'); otherwise prompt interactively
if (( $# > 0 )); then
  if [[ "$1" == "all" ]]; then
    SELECTED_MODULES=("${AVAILABLE_MODULES[@]}")
  elif [[ "$1" == "none" ]]; then
    SELECTED_MODULES=()
  else
    SELECTED_MODULES=("$@")
  fi
else
  echo "Building patch version: $VERSION"
  if (( ${#AVAILABLE_MODULES} > 0 )); then
    echo "Available top-level modules:"
    for i in {1..${#AVAILABLE_MODULES}}; do
      echo "  [$i] ${AVAILABLE_MODULES[$i]}"
    done
    echo ""
    read "CHOICES?Enter module numbers separated by spaces (or 'all' / 'none'): "
  else
    CHOICES="none"
  fi

  SELECTED_MODULES=()
  if [[ "$CHOICES" == "all" ]]; then
    SELECTED_MODULES=("${AVAILABLE_MODULES[@]}")
  elif [[ "$CHOICES" != "none" && -n "$CHOICES" ]]; then
    for idx in ${=CHOICES}; do
      if [[ -n "${AVAILABLE_MODULES[$idx]}" ]]; then
        SELECTED_MODULES+=("${AVAILABLE_MODULES[$idx]}")
      fi
    done
  fi
fi

# Validate that every selected module exists
for mod in "${SELECTED_MODULES[@]}"; do
  if [[ ! -d "$mod" ]]; then
    echo "Error: Module folder '$mod' does not exist in $PWD"
    exit 1
  fi
done

mkdir -p patches
OUT="patches/av-panel-patch-${VERSION}.zip"
TMP_DIR=$(mktemp -d)

# Build valid JSON array of included modules
QUOTED_MODULES=()
for mod in "${SELECTED_MODULES[@]}"; do
  QUOTED_MODULES+=("\"${mod}\"")
done
MODULES_JSON="[${(j:, :)QUOTED_MODULES}]"

MANIFEST_CONTENT=$(printf '{\n  "target": "av-touchpanel",\n  "version": "%s",\n  "modules": %s,\n  "created": "%s"\n}\n' \
  "$VERSION" "$MODULES_JSON" "$(date -u +%Y-%m-%dT%H:%M:%SZ)")

printf '%s\n' "$MANIFEST_CONTENT" > "$TMP_DIR/manifest.json"
printf '%s\n' "$MANIFEST_CONTENT" > app/patch-manifest.json

# 1. Stage core directories (never overwrite vendor/composer autoloader hashes)
rsync -a --exclude='.DS_Store' app/ "$TMP_DIR/app/"
cp composer.json "$TMP_DIR/composer.json"
rsync -a --exclude='.DS_Store' --exclude='assets/' public/ "$TMP_DIR/public/"

# 2. Stage selected top-level module directories
for mod in "${SELECTED_MODULES[@]}"; do
  rsync -a --exclude='.DS_Store' --exclude='.git/' "${mod}/" "$TMP_DIR/${mod}/"
done

(cd "$TMP_DIR" && zip -rq "$OLDPWD/$OUT" .)
rm -rf "$TMP_DIR"

echo ""
echo "Created patch bundle: $OUT"
echo "Verified manifest.json:"
unzip -p "$OUT" manifest.json
