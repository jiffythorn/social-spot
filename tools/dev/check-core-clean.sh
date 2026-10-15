#!/usr/bin/env bash
#
# Guards the one rule that keeps this fork easy to update: never change Friendica's own code.
#
#   tools/dev/check-core-clean.sh
#
# The whole upgrade story rests on it. If nothing under version control has been modified,
# then pulling the next upstream release is a fast-forward with no merge and no conflicts, and
# every customization keeps working because all of it lives in directories upstream ignores
# (`/addon` is in Friendica's own .gitignore) or in brand new directories.
#
# Run this before every upstream sync and in CI. It exits non-zero on the first problem.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CORE="$REPO_ROOT/friendica"

green() { printf '\033[32m%s\033[0m' "$*"; }
red()   { printf '\033[31m%s\033[0m' "$*"; }
dim()   { printf '\033[2m%s\033[0m' "$*"; }

FAILED=0

cd "$CORE" || { echo "no friendica checkout at $CORE" >&2; exit 2; }

echo "Checking that Friendica's own code is untouched..."

# 1. Any tracked file that differs from upstream, or was deleted, breaks the fast-forward.
DIRTY="$(git status --porcelain --untracked-files=no)"

if [[ -z "$DIRTY" ]]; then
	echo "  $(green PASS) no tracked file has been modified or deleted"
else
	echo "  $(red FAIL) these tracked files differ from upstream:"
	echo "$DIRTY" | sed 's/^/         /'
	cat <<'EOF'

         These are edits to Friendica itself. Move the behaviour into our own sources --
         addon/orgsuite/ or theme/orgtool/ at the repository root, which are linked into
         the core tree rather than living inside it -- for example a hook, a route, or a
         template in our theme. Otherwise a future update will conflict with it.
EOF
	FAILED=$((FAILED + 1))
fi

# 2. Make sure our additions really are outside the tracked tree.
UNTRACKED="$(git status --porcelain | grep '^??' | awk '{print $2}')"

if [[ -z "$UNTRACKED" ]]; then
	echo "  $(dim --) no untracked additions"
else
	while read -r path; do
		[[ -z "$path" ]] && continue

		git check-ignore -q "$path" 2>/dev/null
		ignored=$?

		# A new theme directory is additive, not an edit: upstream has no file at that path,
		# so it can never conflict. Anything else being untracked is worth a look.
		if [[ "$ignored" == "0" ]]; then
			echo "  $(green PASS) $path is ignored by upstream's .gitignore"
		elif [[ "$path" == view/theme/* ]]; then
			echo "  $(green PASS) $path is a new theme directory (nothing upstream to conflict with)"
		else
			echo "  $(red WARN) $path is untracked and not ignored; confirm it is a new path upstream does not own"
			FAILED=$((FAILED + 1))
		fi
	done <<< "$UNTRACKED"
fi

# 3. Confirm the addon lives where an update cannot reach it.
ADDON="$CORE/addon/orgsuite"

if [[ -e "$ADDON" ]]; then
	# Tests the path of the addon itself rather than a file inside it. The sources live at the
	# repository root and are symlinked in, and git refuses to evaluate a pathspec that goes
	# *through* a symbolic link ("pathspec ... is beyond a symbolic link"), which turned this
	# into a failure on a tree that is entirely correct.
	git check-ignore -q "addon/orgsuite" \
		&& echo "  $(green PASS) addon/orgsuite is inside upstream's ignored addon directory" \
		|| { echo "  $(red FAIL) addon/orgsuite is NOT ignored by upstream; it will conflict on update"; FAILED=$((FAILED + 1)); }
fi

echo

if [[ "$FAILED" -eq 0 ]]; then
	echo "$(green 'Core is clean') — a re-fork or 'git pull' of upstream will fast-forward with no conflicts."
	exit 0
fi

echo "$(red "$FAILED problem(s)") — fix these before syncing from upstream."
exit 1
