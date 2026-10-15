#!/usr/bin/env bash
#
# Links this project's own sources into the Friendica checkout, so that "a fresh clone plus a
# freshly fetched core" behaves exactly like an existing development tree.
#
#   tools/dev/link-into-core.sh
#
# Why our code is not simply kept inside friendica/:
#
#   * friendica/ is not part of this repository. It is a checkout of upstream Friendica, fetched
#     on demand, and excluded by .gitignore. A file inside it is invisible to git here, which is
#     how our addon came to be in no repository at all — `git ls-files addon/` returned nothing.
#   * upstream's own .gitignore excludes addon/, and has no knowledge of view/theme/orgtool/, so
#     neither path can conflict with any upstream release.
#
# At runtime Friendica looks for addons in addon/ and themes in view/theme/, so both are
# symlinked into place. Symlinks rather than copies, so a running development instance keeps
# serving the edited file instead of a stale duplicate.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CORE="$REPO_ROOT/friendica"

log() { printf '\033[36m[link]\033[0m %s\n' "$*"; }
ok()  { printf '\033[32m[link]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[link] %s\033[0m\n' "$*" >&2; exit 1; }

[[ -d "$CORE" ]] || die "no Friendica checkout at $CORE — fetch core first (see .github/workflows/verify.yml)"

link_into() {
  local source="$1" linkname="$2" target="$3"

  [[ -e "$source" ]] || die "our source is missing: $source"

  mkdir -p "$(dirname "$linkname")"

  if [[ -L "$linkname" ]]; then
    # Replacing an existing symlink is the idempotent case: this script is meant to be re-run.
    rm -f "$linkname"
  elif [[ -e "$linkname" ]]; then
    # A real file or directory here means someone has the old layout, or upstream has started
    # shipping this exact path. Silently nesting a link inside it would be much harder to debug.
    die "$linkname already exists and is not a symlink.
    Move it aside first: mv '$linkname' '$linkname.old'"
  fi

  ln -s "$target" "$linkname"

  if [[ ! -e "$linkname" ]]; then
    die "the link at $linkname does not resolve; target was '$target'"
  fi

  log "$linkname -> $target"
}

# Targets are relative to the directory holding the link, so the tree stays portable.
link_into "$REPO_ROOT/addon/orgsuite"  "$CORE/addon/orgsuite"     "../../addon/orgsuite"
link_into "$REPO_ROOT/theme/orgtool"   "$CORE/view/theme/orgtool" "../../../theme/orgtool"

# Prove the important end of each link actually resolves to a real file: a dangling symlink would
# otherwise only show up as a confusing failure much later.
[[ -f "$CORE/addon/orgsuite/orgsuite.php" ]] || die "friendica/addon/orgsuite/orgsuite.php is not readable through the link"
[[ -f "$CORE/view/theme/orgtool/theme.php" ]] || die "friendica/view/theme/orgtool/theme.php is not readable through the link"

ok "our sources are linked into core"
