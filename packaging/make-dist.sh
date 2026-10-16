#!/usr/bin/env bash
#
# Builds the distributable release zip: everything of ours, nothing else.
#
#   bash packaging/make-dist.sh [version] [--allow-dirty]
#
# Produces dist/social-spot-<version>.zip plus a .sha256 checksum file. The
# contents come from `git archive HEAD`, which means three guarantees that
# matter for distribution:
#   * only committed files ship - forgotten local edits cannot leak into a release;
#   * friendica/ and .local/ are excluded by .gitignore (core is fetched at
#     install time, runtime state never ships);
#   * packaging/.env is excluded by .gitignore, so passwords cannot ship either.
#     The script asserts all three exclusions after building, rather than
#     assuming the ignore file says what it thinks it says.
#
# Attach the zip to a GitHub Release and point office staff at it; the
# step-by-step guide they follow is docs/office-install-guide.md.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

say()  { printf '\033[36m[dist]\033[0m %s\n' "$*"; }
die()  { printf '\033[31m[dist]\033[0m %s\n' "$*" >&2; exit 1; }

VERSION="${1:-}"
ALLOW_DIRTY=0
[[ "${2:-}${1:-}" == *"--allow-dirty"* ]] && ALLOW_DIRTY=1

if [[ -z "$VERSION" || "$VERSION" == "--allow-dirty" ]]; then
  # Prefer an exact tag (releases should be tagged), fall back to today's date.
  VERSION="$(git describe --tags --exact-match 2>/dev/null || date +%Y.%m.%d)"
fi

if [[ -n "$(git status --porcelain)" && "$ALLOW_DIRTY" -eq 0 ]]; then
  die "the tree has uncommitted changes - commit first, or pass --allow-dirty
  to ship anyway (the zip still contains only committed files)."
fi

command -v zip >/dev/null || die "the 'zip' tool is not installed."
command -v unzip >/dev/null || die "the 'unzip' tool is not installed."

NAME="social-spot-$VERSION"
OUT="dist/$NAME.zip"
mkdir -p dist

say "archiving HEAD as $NAME"
git archive --format=zip --prefix="$NAME/" -o "$OUT" HEAD \
  || die "git archive failed"

say "proving the zip contains what it should (and nothing it should not)"
list="$(unzip -l "$OUT" | awk '{print $4}')"
has() { printf '%s' "$list" | grep -qx "$1"; }

for need in \
  "$NAME/addon/orgsuite/orgsuite.php" \
  "$NAME/theme/orgtool/theme.php" \
  "$NAME/packaging/compose.yaml" \
  "$NAME/packaging/Dockerfile" \
  "$NAME/packaging/install.sh" \
  "$NAME/packaging/install.ps1" \
  "$NAME/packaging/Install-Windows.bat" \
  "$NAME/packaging/backup.sh" \
  "$NAME/packaging/Backup-Windows.bat" \
  "$NAME/docs/office-install-guide.md" \
  "$NAME/docs/mobile-apps.md" \
  "$NAME/docs/packaging.md" \
  "$NAME/packaging/.env.example"; do
  has "$need" || die "missing from the zip: $need"
done

# Directory prefixes match by prefix; the file matches a whole line, so that
# packaging/.env.example (which MUST ship) is not mistaken for packaging/.env
# (which must never ship).
for banned in "$NAME/friendica/" "$NAME/.local/" "$NAME/.git/"; do
  printf '%s' "$list" | grep -q "^$banned" \
    && die "must never ship, but present in the zip: $banned"
done
printf '%s' "$list" | grep -qx "$NAME/packaging/.env" \
  && die "must never ship, but present in the zip: $NAME/packaging/.env"

sha256sum "$OUT" > "$OUT.sha256"
say "wrote $OUT ($(du -h "$OUT" | cut -f1)) + .sha256"
say "next: attach it to a GitHub Release; staff follow docs/office-install-guide.md"
