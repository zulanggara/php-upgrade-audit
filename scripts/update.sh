#!/usr/bin/env bash
# Keep this kit up to date. SKILL.md and AGENTS.md run it at the start of every audit; it checks at most once
# a day unless --force is given, and never stops an audit (it always exits 0, except on wrong usage).
#
#   bash scripts/update.sh            check (once a day) and update
#   bash scripts/update.sh --force    check now
#   bash scripts/update.sh --check    only report whether an update exists; change nothing
#   bash scripts/update.sh --offline  do nothing (same as PHP_UPGRADE_AUDIT_NO_UPDATE=1)
#
# git clone (skill folder or any clone): fast-forwards to the default branch of origin, then re-runs setup.sh
#   when the analysis tools changed. A clone with local changes, on another branch or with own commits is never
#   touched: you get a notice instead.
# Plugin or other copy: compares the version with the published one; for a Claude Code plugin it runs
#   `claude plugin update` when the claude CLI is available, otherwise prints the command.
#
# The last line is for agents: "update: up-to-date | updated | available | skipped | failed".
# Network: one `git fetch` from origin, or one HTTPS request for the published plugin.json.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
PUBLISHED_JSON="${PHP_UPGRADE_AUDIT_UPDATE_URL:-https://raw.githubusercontent.com/zulanggara/php-upgrade-audit/HEAD/.claude-plugin/plugin.json}"
FORCE=0; CHECK=0
for a in "$@"; do
  case "$a" in
    --force) FORCE=1 ;;
    --check) CHECK=1 ;;
    --offline) echo "update: skipped (--offline)"; exit 0 ;;
    -h|--help) sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Error: unknown option $a. Run: bash scripts/update.sh --help" >&2; exit 1 ;;
  esac
done
say() { echo "php-upgrade-audit: $*"; }
done_with() { echo "update: $1"; exit 0; }
version_of() { php -r '$j = json_decode((string) @file_get_contents($argv[1]), true); echo is_array($j) ? ($j["version"] ?? "?") : "?";' "$1" 2>/dev/null || echo "?"; }

[ "${PHP_UPGRADE_AUDIT_NO_UPDATE:-}" = "1" ] && done_with "skipped (PHP_UPGRADE_AUDIT_NO_UPDATE=1)"
command -v php >/dev/null 2>&1 || done_with "skipped (php not found)"
LOCAL_VERSION="$(version_of "$DIR/.claude-plugin/plugin.json")"

IS_GIT=0
if command -v git >/dev/null 2>&1 && [ "$(git -C "$DIR" rev-parse --show-toplevel 2>/dev/null)" = "$DIR" ]; then IS_GIT=1; fi
if [ $IS_GIT = 1 ]; then STAMP="$(git -C "$DIR" rev-parse --absolute-git-dir)/php-upgrade-audit-update-check"; else STAMP="$DIR/tools/.update-check"; fi

# Once a day: repeat the last result instead of going to the network again.
if [ $FORCE = 0 ] && [ -f "$STAMP" ] && [ -n "$(find "$STAMP" -mmin -1440 2>/dev/null)" ]; then
  say "$LOCAL_VERSION, checked in the last 24 h ($(head -1 "$STAMP")). Use --force to check now."
  done_with "$(tail -1 "$STAMP")"
fi
remember() { mkdir -p "$(dirname "$STAMP")" 2>/dev/null; printf '%s\n%s\n' "$(date -u +%Y-%m-%dT%H:%MZ)" "$1" > "$STAMP" 2>/dev/null; done_with "$1"; }
with_timeout() { if command -v timeout >/dev/null 2>&1; then timeout "$@"; else shift; "$@"; fi; }

if [ $IS_GIT = 1 ]; then
  git -C "$DIR" remote get-url origin >/dev/null 2>&1 || done_with "skipped (git clone without an origin remote)"
  if ! with_timeout 30 git -C "$DIR" fetch --quiet origin 2>/dev/null; then
    say "could not reach origin; not checked."
    done_with "failed"
  fi
  DEFAULT="$(git -C "$DIR" symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null || echo origin/main)"
  git -C "$DIR" rev-parse --verify --quiet "$DEFAULT" >/dev/null || DEFAULT="origin/main"
  BEHIND="$(git -C "$DIR" rev-list --count "HEAD..$DEFAULT" 2>/dev/null || echo 0)"
  AHEAD="$(git -C "$DIR" rev-list --count "$DEFAULT..HEAD" 2>/dev/null || echo 0)"
  NEW_VERSION="$(git -C "$DIR" show "$DEFAULT:.claude-plugin/plugin.json" 2>/dev/null | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo $j["version"] ?? "?";')"
  if [ "$BEHIND" = 0 ]; then
    say "$LOCAL_VERSION is up to date."
    remember "up-to-date"
  fi
  BRANCH="$(git -C "$DIR" rev-parse --abbrev-ref HEAD)"
  if [ $CHECK = 1 ]; then
    say "update available: $LOCAL_VERSION -> $NEW_VERSION ($BEHIND commit(s)). Run: bash $DIR/scripts/update.sh --force"
    done_with "available"
  fi
  if [ -n "$(git -C "$DIR" status --porcelain --untracked-files=no)" ] || [ "$AHEAD" != 0 ] || [ "$BRANCH" != "${DEFAULT#origin/}" ]; then
    say "update available ($LOCAL_VERSION -> $NEW_VERSION, $BEHIND commit(s)), but this clone has local changes, own commits or is on branch $BRANCH, so it was not touched. Update it yourself: git -C $DIR pull --ff-only"
    remember "available"
  fi
  OLD="$(git -C "$DIR" rev-parse HEAD)"
  if ! git -C "$DIR" merge --ff-only --quiet "$DEFAULT" 2>/dev/null; then
    say "fast-forward failed; nothing changed."
    done_with "failed"
  fi
  if ! git -C "$DIR" diff --quiet "$OLD" HEAD -- tools/composer.json tools/composer.lock; then
    say "the analysis tools changed: running setup.sh"
    bash "$DIR/scripts/setup.sh" > "$DIR/tools/.setup.log" 2>&1 || say "setup.sh failed, see $DIR/tools/.setup.log"
  fi
  say "updated $LOCAL_VERSION -> $(version_of "$DIR/.claude-plugin/plugin.json") ($BEHIND commit(s)):"
  git -C "$DIR" log --format='  - %s' "$OLD..HEAD" | head -15
  say "instructions may have changed: read SKILL.md / AGENTS.md again before continuing."
  remember "updated"
fi

# Not a git clone (Claude Code plugin cache, a download): compare with the published version.
PUBLISHED="$(php -r '$c = stream_context_create(["http" => ["timeout" => 10, "user_agent" => "php-upgrade-audit update check"]]);
  $j = json_decode((string) @file_get_contents($argv[1], false, $c), true); echo is_array($j) ? ($j["version"] ?? "") : "";' "$PUBLISHED_JSON" 2>/dev/null)"
if [ -z "$PUBLISHED" ]; then
  say "could not read the published version; not checked."
  done_with "failed"
fi
if ! php -r 'exit(version_compare($argv[2], $argv[1], ">") ? 0 : 1);' "$LOCAL_VERSION" "$PUBLISHED"; then
  say "$LOCAL_VERSION is up to date."
  remember "up-to-date"
fi
case "$DIR" in
  */.claude/plugins/*)
    if [ $CHECK = 0 ] && command -v claude >/dev/null 2>&1 \
      && with_timeout 120 claude plugin marketplace update php-upgrade-audit >/dev/null 2>&1 \
      && with_timeout 120 claude plugin update php-upgrade-audit@php-upgrade-audit >/dev/null 2>&1; then
      say "plugin updated $LOCAL_VERSION -> $PUBLISHED. It takes effect in the next session (or after /reload-plugins); this run continues with $LOCAL_VERSION."
      remember "updated"
    fi
    say "update available: $LOCAL_VERSION -> $PUBLISHED. In Claude Code run: /plugin marketplace update php-upgrade-audit (or turn on auto-update: /plugin > Marketplaces > php-upgrade-audit > Enable auto-update)."
    remember "available" ;;
  *)
    say "update available: $LOCAL_VERSION -> $PUBLISHED. This copy is not a git clone: download it again from https://github.com/zulanggara/php-upgrade-audit, or clone it so updates are automatic."
    remember "available" ;;
esac
