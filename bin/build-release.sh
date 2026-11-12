#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="ajmcp-wp"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASES_DIR="$ROOT_DIR/releases"
PLUGIN_FILE="$ROOT_DIR/ajmcp-wp.php"

GITHUB_REPO="ssnanda/ajmcp-wp"
TAG_PREFIX="v"
SITES_ROOT="/Users/sandip/Projects/sites"

GITHUB_RELEASE="true"
GIT_COMMIT="true"
GIT_PUSH="true"
RELEASE_BRANCH="ask"
USE_CURRENT_BRANCH="true"
CREATE_BRANCH="false"
MAIN_RELEASE_BRANCH="main"
MERGE_TO_MAIN="ask"

usage() {
  cat <<'USAGE'
Usage:
  ./bin/build-release.sh

Interactive flow (only the version bump prompts; everything else is automatic):
  1. Choose version bump
  2. Build releases/<slug>-<version>.zip on the current branch
  3. Commit version changes to git
  4. Push branch and version tag to GitHub
  5. Create/upload GitHub Release asset
  6. Deploy to local ddev sites under /Users/sandip/Projects/sites
  7. For non-main branches, skip merge back to main by default

Options are still supported:
  --version X.Y.Z
  --bump patch|minor|major
  --no-bump
  --slug NAME
  --root PATH
  --repo OWNER/REPO
  --branch NAME
  --new-branch NAME
  --current-branch
  --main-branch NAME
  --merge-main
  --no-merge-main
  --github-release
  --no-github-release
  --git-commit
  --no-git-commit
  --push
  --no-push
  --help

Creates:
  releases/<slug>-<version>.zip
  releases/<slug>.zip
USAGE
}

ask_yes_no() {
  local prompt="$1"
  local default="${2:-n}"
  local answer=""

  if [[ "$default" == "y" ]]; then
    read -r -p "$prompt [Y/n]: " answer
    answer="${answer:-y}"
  else
    read -r -p "$prompt [y/N]: " answer
    answer="${answer:-n}"
  fi

  case "$answer" in
    y|Y|yes|YES|Yes) return 0 ;;
    *) return 1 ;;
  esac
}

ask_text() {
  local prompt="$1"
  local default="${2:-}"
  local answer=""

  if [[ -n "$default" ]]; then
    read -r -p "$prompt [$default]: " answer
    echo "${answer:-$default}"
  else
    read -r -p "$prompt: " answer
    echo "$answer"
  fi
}

get_version() {
  awk -F':' 'tolower($1) ~ /version[ \t]*$/ {gsub(/^[ \t]+|[ \t\r]+$/, "", $2); print $2; exit}' "$PLUGIN_FILE"
}

validate_version() {
  local version="$1"
  if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version must use X.Y.Z format, example 1.0.1" >&2
    exit 1
  fi
}

bump_version() {
  local current="$1"
  local part="$2"
  local major minor patch
  IFS='.' read -r major minor patch <<< "$current"

  case "$part" in
    patch) patch=$((patch + 1)) ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    major) major=$((major + 1)); minor=0; patch=0 ;;
    *) echo "Error: bump must be patch, minor, or major" >&2; exit 1 ;;
  esac

  echo "$major.$minor.$patch"
}

set_version() {
  local version="$1"
  validate_version "$version"

  perl -0pi -e "s/Version:\s*[0-9]+\.[0-9]+\.[0-9]+/Version: $version/" "$PLUGIN_FILE"
  perl -0pi -e "s/define\(\s*'AJMCP_WP_VERSION'\s*,\s*'[^']+'\s*\);/define( 'AJMCP_WP_VERSION', '$version' );/" "$PLUGIN_FILE"
  [[ -f "$ROOT_DIR/readme.txt" ]] && perl -0pi -e "s/Stable tag:\s*[0-9]+\.[0-9]+\.[0-9]+/Stable tag: $version/" "$ROOT_DIR/readme.txt"
  [[ -f "$ROOT_DIR/README.md" ]] && perl -0pi -e "s/WordPress plugin \*\*v[0-9]+\.[0-9]+\.[0-9]+\*\*/WordPress plugin **v$version**/" "$ROOT_DIR/README.md"
  return 0
}

require_files() {
  [[ -f "$PLUGIN_FILE" ]] || { echo "Error: missing plugin file at $PLUGIN_FILE" >&2; exit 1; }
  command -v zip >/dev/null 2>&1 || { echo "Error: zip command is required" >&2; exit 1; }
  command -v unzip >/dev/null 2>&1 || { echo "Error: unzip command is required" >&2; exit 1; }
  command -v rsync >/dev/null 2>&1 || { echo "Error: rsync command is required" >&2; exit 1; }
}

require_git() {
  command -v git >/dev/null 2>&1 || { echo "Error: git command is required" >&2; exit 1; }
  git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
    echo "Error: $ROOT_DIR is not a git repository" >&2
    exit 1
  }
}

git_worktree_dirty() {
  require_git
  cd "$ROOT_DIR"
  [[ -n "$(git status --porcelain)" ]]
}

git_branch_exists() {
  local branch="$1"
  git -C "$ROOT_DIR" show-ref --verify --quiet "refs/heads/$branch"
}

git_remote_branch_exists() {
  local branch="$1"
  git -C "$ROOT_DIR" ls-remote --exit-code --heads origin "$branch" >/dev/null 2>&1
}

choose_release_branch() {
  require_git

  if [[ "$USE_CURRENT_BRANCH" == "true" ]]; then
    git -C "$ROOT_DIR" symbolic-ref --short HEAD
    return 0
  fi

  if [[ "$CREATE_BRANCH" == "true" ]]; then
    echo "$RELEASE_BRANCH"
    return 0
  fi

  if [[ -n "$RELEASE_BRANCH" && "$RELEASE_BRANCH" != "ask" ]]; then
    echo "$RELEASE_BRANCH"
    return 0
  fi

  local answer
  answer="$(ask_text "Release branch. Use main, an existing branch, or type +new-branch" "main")"

  if [[ "$answer" == +* ]]; then
    CREATE_BRANCH="true"
    answer="${answer#+}"
  fi

  [[ -n "$answer" ]] || answer="main"
  echo "$answer"
}

prepare_release_branch() {
  require_git
  cd "$ROOT_DIR"

  local target_branch="$1"
  local current_branch
  current_branch="$(git symbolic-ref --short HEAD)"

  [[ -n "$target_branch" ]] || { echo "Error: release branch cannot be empty" >&2; exit 1; }

  if [[ "$target_branch" == "$current_branch" ]]; then
    echo "Git: using current branch $current_branch"
    return 0
  fi

  if [[ "$CREATE_BRANCH" == "true" ]]; then
    if git_branch_exists "$target_branch" || git_remote_branch_exists "$target_branch"; then
      echo "Error: branch already exists: $target_branch" >&2
      exit 1
    fi

    git switch -c "$target_branch"
    echo "Git: created and switched to branch $target_branch"
    if git_worktree_dirty; then
      echo "Git: carried your uncommitted changes onto $target_branch"
    fi
    return 0
  fi

  if git_worktree_dirty; then
    echo "Error: cannot switch from $current_branch to existing branch $target_branch with uncommitted changes." >&2
    echo "Commit/stash your changes first, use --new-branch $target_branch to create a branch around these changes, or run with --current-branch." >&2
    exit 1
  fi

  if git_branch_exists "$target_branch"; then
    git switch "$target_branch"
    echo "Git: switched to branch $target_branch"
    return 0
  fi

  if git_remote_branch_exists "$target_branch"; then
    git switch --track "origin/$target_branch"
    echo "Git: checked out tracking branch $target_branch"
    return 0
  fi

  echo "Error: branch does not exist: $target_branch" >&2
  echo "Use --new-branch $target_branch to create it." >&2
  exit 1
}

require_gh() {
  command -v gh >/dev/null 2>&1 || { echo "Error: GitHub CLI is required. Install with: brew install gh" >&2; exit 1; }
  gh auth status >/dev/null 2>&1 || { echo "Error: GitHub CLI is not logged in. Run: gh auth login" >&2; exit 1; }
}

choose_version_from_menu() {
  local current="$1"
  local choice=""
  local custom_version=""

  while true; do
    cat >&2 <<MENU
Current version: $current

Choose release type:
  1) patch  ($(bump_version "$current" patch))
  2) minor  ($(bump_version "$current" minor))
  3) major  ($(bump_version "$current" major))
  4) custom version
  5) no bump / package current version
MENU

    read -r -p "Enter choice (1-5, default=1): " choice
    choice="${choice:-1}"

    case "$choice" in
      1|patch|p) echo "$(bump_version "$current" patch)"; return 0 ;;
      2|minor|m) echo "$(bump_version "$current" minor)"; return 0 ;;
      3|major|M) echo "$(bump_version "$current" major)"; return 0 ;;
      4|custom|c)
        read -r -p "Enter version X.Y.Z: " custom_version
        validate_version "$custom_version"
        echo "$custom_version"
        return 0
        ;;
      5|none|no|n|current) echo "$current"; return 0 ;;
      *) echo "Please choose 1, 2, 3, 4, or 5." >&2; echo "" >&2 ;;
    esac
  done
}

build_zip() {
  local tmp_dir package_dir old_pwd
  tmp_dir="$(mktemp -d)"
  package_dir="$tmp_dir/$PLUGIN_SLUG"
  old_pwd="$(pwd)"

  mkdir -p "$package_dir"

  rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.DS_Store' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='releases' \
    --exclude='bin' \
    --exclude='README.md' \
    --exclude='/CLAUDE.local.md' \
    --exclude='/CLAUDE.md' \
    --exclude='/AGENTS.md' \
    --exclude='/tests' \
    --exclude='/phpunit-*.xml' \
    --exclude='/docs' \
    --exclude='/.github' \
    --exclude='/*.pdf' \
    --exclude='/lslar.txt' \
    --exclude='*.zip' \
    --exclude='.vscode' \
    --exclude='.idea' \
    --exclude='__MACOSX' \
    --exclude='*.swp' \
    --exclude='*.swo' \
    --exclude='*~' \
    "$ROOT_DIR/" "$package_dir/"

  cd "$tmp_dir"

  local built_zip="$tmp_dir/$PLUGIN_SLUG-$VERSION.zip"
  zip -r "$built_zip" "$PLUGIN_SLUG" \
    -x "*/.DS_Store" \
    -x "*/__MACOSX/*" >/dev/null

  # Overwrite (not delete) any earlier build of this version.
  cp -f "$built_zip" "$VERSIONED_ZIP"
  cp -f "$built_zip" "$LATEST_ZIP"

  cd "$old_pwd"
  rm -rf "$tmp_dir"
}

verify_zip() {
  local zip_file="$1"
  local required_file
  local required_files=(
    "ajmcp-wp.php"
    "uninstall.php"
    "readme.txt"
    "includes/class-ajmcp-wp-chat.php"
    "includes/class-ajmcp-wp-client.php"
    "includes/class-ajmcp-wp-settings.php"
  )
  [[ -f "$zip_file" ]] || { echo "Error: zip was not created: $zip_file" >&2; exit 1; }

  if ! unzip -l "$zip_file" | awk '{print $4}' | grep -q "^$PLUGIN_SLUG/$"; then
    echo "Error: zip root folder is not $PLUGIN_SLUG/" >&2
    unzip -l "$zip_file" | head >&2
    exit 1
  fi

  for required_file in "${required_files[@]}"; do
    if ! unzip -l "$zip_file" | awk '{print $4}' | grep -q "^$PLUGIN_SLUG/$required_file$"; then
      echo "Error: zip is missing required runtime file $PLUGIN_SLUG/$required_file" >&2
      exit 1
    fi
  done
}

git_commit_release_files() {
  require_git
  cd "$ROOT_DIR"

  git add -u

  while IFS= read -r file; do
    case "$file" in
      releases/*|*.zip) continue ;;
      *) git add "$file" ;;
    esac
  done < <(git ls-files --others --exclude-standard)

  if git diff --cached --quiet; then
    echo "Git: nothing to commit"
    return 0
  fi

  git commit -m "Release AJMCP WordPress Connector $VERSION"
}

git_create_tag() {
  require_git
  cd "$ROOT_DIR"
  local tag
  tag="$(get_release_tag)"

  if git rev-parse "$tag" >/dev/null 2>&1; then
    echo "Git: tag already exists: $tag"
  else
    git tag "$tag"
    echo "Git: created tag $tag"
  fi
}

merge_release_branch_to_main() {
  require_git
  cd "$ROOT_DIR"

  local source_branch
  source_branch="$(git symbolic-ref --short HEAD)"

  if [[ "$source_branch" == "$MAIN_RELEASE_BRANCH" ]]; then
    echo "Git: already on $MAIN_RELEASE_BRANCH; merge skipped"
    return 0
  fi

  if git_worktree_dirty; then
    echo "Error: cannot merge $source_branch into $MAIN_RELEASE_BRANCH with uncommitted changes." >&2
    echo "Commit or stash changes first, then rerun with --merge-main." >&2
    exit 1
  fi

  if ! git_branch_exists "$MAIN_RELEASE_BRANCH"; then
    if git_remote_branch_exists "$MAIN_RELEASE_BRANCH"; then
      git switch --track "origin/$MAIN_RELEASE_BRANCH"
    else
      echo "Error: main branch does not exist: $MAIN_RELEASE_BRANCH" >&2
      exit 1
    fi
  else
    git switch "$MAIN_RELEASE_BRANCH"
  fi

  git merge --no-ff "$source_branch" -m "Merge $source_branch into $MAIN_RELEASE_BRANCH"
  echo "Git: merged $source_branch into $MAIN_RELEASE_BRANCH"
}

git_push_release() {
  require_git
  cd "$ROOT_DIR"

  local current_branch
  current_branch="$(git symbolic-ref --short HEAD)"

  git push -u origin "$current_branch"
  git push origin "$(get_release_tag)"
}

sanitize_branch_for_tag() {
  local branch="$1"
  echo "$branch" | tr '/[:space:]' '--' | tr -cd '[:alnum:]._-'
}

is_main_release_branch() {
  local current_branch
  current_branch="$(git -C "$ROOT_DIR" symbolic-ref --short HEAD)"
  [[ "$current_branch" == "$MAIN_RELEASE_BRANCH" ]]
}

get_release_tag() {
  local current_branch suffix
  current_branch="$(git -C "$ROOT_DIR" symbolic-ref --short HEAD)"

  if [[ "$current_branch" == "$MAIN_RELEASE_BRANCH" ]]; then
    echo "$TAG_PREFIX$VERSION"
    return 0
  fi

  suffix="$(sanitize_branch_for_tag "$current_branch")"
  echo "$TAG_PREFIX$VERSION-dev-$suffix"
}

publish_github_release() {
  require_gh

  local tag title notes
  tag="$(get_release_tag)"

  if is_main_release_branch; then
    title="AJMCP WordPress Connector $VERSION"
    notes="AJMCP WordPress Connector release $VERSION"
  else
    title="AJMCP WordPress Connector $VERSION Developer Build ($(git -C "$ROOT_DIR" symbolic-ref --short HEAD))"
    notes="Developer build from branch $(git -C "$ROOT_DIR" symbolic-ref --short HEAD)."
  fi

  if gh release view "$tag" --repo "$GITHUB_REPO" >/dev/null 2>&1; then
    gh release upload "$tag" "$VERSIONED_ZIP" --repo "$GITHUB_REPO" --clobber
    echo "GitHub: uploaded asset to existing release $tag"
  else
    local release_args=(release create "$tag" "$VERSIONED_ZIP" --repo "$GITHUB_REPO" --title "$title" --notes "$notes")
    if ! is_main_release_branch; then
      release_args+=(--prerelease)
    fi

    gh "${release_args[@]}"
    echo "GitHub: created release $tag and uploaded asset"
  fi
}

find_local_sites_with_plugin() {
  local site_dir
  [[ -d "$SITES_ROOT" ]] || return 0

  for site_dir in "$SITES_ROOT"/*/; do
    [[ -d "$site_dir" ]] || continue
    [[ -d "${site_dir}wp-content/plugins/$PLUGIN_SLUG" ]] || continue
    basename "$site_dir"
  done
}

# Moves a file or folder to the macOS Trash (never deletes it outright).
trash_path() {
  local target="$1"
  [[ -e "$target" ]] || return 0
  osascript -e "tell application \"Finder\" to delete POSIX file \"$target\"" >/dev/null 2>&1 || {
    mkdir -p "$HOME/.Trash"
    mv "$target" "$HOME/.Trash/$(basename "$target").$(date +%s)"
  }
}

deploy_to_local_site() {
  local site_name="$1"
  local plugins_dir="$SITES_ROOT/$site_name/wp-content/plugins"
  echo "  → $site_name ..."
  trash_path "${plugins_dir:?}/$PLUGIN_SLUG"
  unzip -q "$VERSIONED_ZIP" -d "$plugins_dir/"
  echo "  ✓ $site_name"
}

local_deploy_step() {
  local available_sites=()
  local site
  while IFS= read -r site; do
    [[ -n "$site" ]] && available_sites+=("$site")
  done < <(find_local_sites_with_plugin)

  if [[ ${#available_sites[@]} -eq 0 ]]; then
    echo ""
    echo "Local deploy: no local sites with $PLUGIN_SLUG found under $SITES_ROOT"
    return 0
  fi

  echo ""
  echo "Local deploy: updating AJMCP WordPress Connector $VERSION in all matching local sites under $SITES_ROOT"
  for site in "${available_sites[@]}"; do
    echo "  • $site"
  done
  echo ""

  for site in "${available_sites[@]}"; do
    deploy_to_local_site "$site"
  done
  echo ""
  echo "Local deploy complete: AJMCP WordPress Connector $VERSION installed in ${#available_sites[@]} site(s)"
}

VERSION_OVERRIDE=""
BUMP_PART=""
NO_BUMP="false"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION_OVERRIDE="${2:-}"; shift 2 ;;
    --bump) BUMP_PART="${2:-}"; shift 2 ;;
    --no-bump) NO_BUMP="true"; shift ;;
    --slug) PLUGIN_SLUG="${2:-}"; shift 2 ;;
    --root)
      ROOT_DIR="$(cd "${2:-}" && pwd)"
      RELEASES_DIR="$ROOT_DIR/releases"
      PLUGIN_FILE="$ROOT_DIR/ajmcp-wp.php"
      shift 2
      ;;
    --repo) GITHUB_REPO="${2:-}"; shift 2 ;;
    --branch) RELEASE_BRANCH="${2:-}"; USE_CURRENT_BRANCH="false"; CREATE_BRANCH="false"; shift 2 ;;
    --new-branch) RELEASE_BRANCH="${2:-}"; USE_CURRENT_BRANCH="false"; CREATE_BRANCH="true"; shift 2 ;;
    --current-branch) USE_CURRENT_BRANCH="true"; CREATE_BRANCH="false"; shift ;;
    --main-branch) MAIN_RELEASE_BRANCH="${2:-}"; shift 2 ;;
    --merge-main) MERGE_TO_MAIN="true"; shift ;;
    --no-merge-main) MERGE_TO_MAIN="false"; shift ;;
    --github-release) GITHUB_RELEASE="true"; shift ;;
    --no-github-release) GITHUB_RELEASE="false"; shift ;;
    --git-commit) GIT_COMMIT="true"; shift ;;
    --no-git-commit) GIT_COMMIT="false"; shift ;;
    --push) GIT_PUSH="true"; shift ;;
    --no-push) GIT_PUSH="false"; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Error: unknown option $1" >&2; usage; exit 1 ;;
  esac
done

[[ -n "$PLUGIN_SLUG" ]] || { echo "Error: plugin slug cannot be empty" >&2; exit 1; }
[[ -n "$GITHUB_REPO" ]] || { echo "Error: GitHub repo cannot be empty" >&2; exit 1; }
[[ -n "$RELEASE_BRANCH" ]] || { echo "Error: release branch cannot be empty" >&2; exit 1; }

require_files
require_git

RELEASE_BRANCH="$(choose_release_branch)"
prepare_release_branch "$RELEASE_BRANCH"

CURRENT_VERSION="$(get_version)"
validate_version "$CURRENT_VERSION"

ACTION_COUNT=0
[[ -n "$VERSION_OVERRIDE" ]] && ACTION_COUNT=$((ACTION_COUNT + 1))
[[ -n "$BUMP_PART" ]] && ACTION_COUNT=$((ACTION_COUNT + 1))
[[ "$NO_BUMP" == "true" ]] && ACTION_COUNT=$((ACTION_COUNT + 1))

if (( ACTION_COUNT > 1 )); then
  echo "Error: use only one of --version, --bump, or --no-bump" >&2
  exit 1
fi

if [[ -n "$VERSION_OVERRIDE" ]]; then
  NEXT_VERSION="$VERSION_OVERRIDE"
elif [[ -n "$BUMP_PART" ]]; then
  NEXT_VERSION="$(bump_version "$CURRENT_VERSION" "$BUMP_PART")"
elif [[ "$NO_BUMP" == "true" ]]; then
  NEXT_VERSION="$CURRENT_VERSION"
else
  NEXT_VERSION="$(choose_version_from_menu "$CURRENT_VERSION")"
fi

validate_version "$NEXT_VERSION"

if [[ "$NEXT_VERSION" != "$CURRENT_VERSION" ]]; then
  set_version "$NEXT_VERSION"
fi

VERSION="$(get_version)"
validate_version "$VERSION"

mkdir -p "$RELEASES_DIR"
VERSIONED_ZIP="$RELEASES_DIR/$PLUGIN_SLUG-$VERSION.zip"
LATEST_ZIP="$RELEASES_DIR/$PLUGIN_SLUG.zip"

build_zip
verify_zip "$VERSIONED_ZIP"

if [[ "$GIT_COMMIT" == "ask" ]]; then
  if ask_yes_no "Commit version changes to git?" "y"; then
    GIT_COMMIT="true"
  else
    GIT_COMMIT="false"
  fi
fi

if [[ "$GIT_COMMIT" == "true" ]]; then
  git_commit_release_files
fi

if [[ "$GIT_PUSH" == "ask" ]]; then
  GIT_PUSH="true"
  echo "Git: defaulting to push branch and version tag to GitHub"
fi

if [[ "$GIT_PUSH" == "true" ]]; then
  git_create_tag
  git_push_release
fi

if [[ "$GITHUB_RELEASE" == "ask" ]]; then
  GITHUB_RELEASE="true"
  echo "GitHub: defaulting to create/update GitHub Release and upload zip"
fi

if [[ "$GITHUB_RELEASE" == "true" ]]; then
  git_create_tag
  publish_github_release
fi

BUILT_RELEASE_BRANCH="$(git -C "$ROOT_DIR" symbolic-ref --short HEAD)"
BUILT_RELEASE_TAG="$(get_release_tag)"
if is_main_release_branch; then
  BUILT_RELEASE_CHANNEL="normal"
else
  BUILT_RELEASE_CHANNEL="developer"
fi

if ! is_main_release_branch; then
  if [[ "$MERGE_TO_MAIN" == "ask" ]]; then
    MERGE_TO_MAIN="false"
    echo "Git: defaulting to skip merge into $MAIN_RELEASE_BRANCH"
  fi

  if [[ "$MERGE_TO_MAIN" == "true" ]]; then
    merge_release_branch_to_main
  fi
fi

echo "Current version: $CURRENT_VERSION"
echo "New version: $VERSION"
echo "Current branch: $(git -C "$ROOT_DIR" symbolic-ref --short HEAD)"
echo "Release branch: $BUILT_RELEASE_BRANCH"
echo "Release channel: $BUILT_RELEASE_CHANNEL"
echo "Release tag: $BUILT_RELEASE_TAG"
echo "Updated: $PLUGIN_FILE"
echo "Built: $VERSIONED_ZIP"
echo "Built: $LATEST_ZIP"
echo ""
echo "Verified:"
echo "  $PLUGIN_SLUG/"
echo "  $PLUGIN_SLUG/ajmcp-wp.php"
echo ""
echo "Verify manually with:"
echo "  unzip -l \"$VERSIONED_ZIP\" | head"

local_deploy_step

print_version_big() {
  local version="${VERSION:-?}"

  echo ""
  if command -v figlet >/dev/null 2>&1; then
    printf '\033[1;32m'
    figlet -f big "v${version}"
    printf '\033[0m'
  else
    local spaced="" char
    for (( i=0; i<${#version}; i++ )); do
      char="${version:$i:1}"
      spaced+=" $char"
    done
    local inner="  v${spaced}  "
    local bar
    bar=$(printf '═%.0s' $(seq 1 ${#inner}))

    echo ""
    printf '  \033[1;32m╔%s╗\033[0m\n'              "$bar"
    printf '  \033[1;32m║%s║\033[0m\n'              "$bar" | tr '═' ' '
    printf '  \033[1;32m║\033[1;97m%s\033[1;32m║\033[0m\n' "$inner"
    printf '  \033[1;32m║%s║\033[0m\n'              "$bar" | tr '═' ' '
    printf '  \033[1;32m╚%s╝\033[0m\n'              "$bar"
  fi
  echo ""
}

print_version_big
