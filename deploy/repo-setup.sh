#!/bin/bash
# =============================================================================
# GitHub Repository Setup for Moodle 5.03
#
# This script sets up your organization's Moodle repo as a fork-like structure
# that tracks upstream Moodle for easy updates while keeping your customizations.
#
# Run this on your LOCAL machine (needs git + gh CLI authenticated)
#
# Usage: bash repo-setup.sh <github-org-name>
# Example: bash repo-setup.sh sylr-org
# =============================================================================

set -euo pipefail

ORG="${1:?Usage: bash repo-setup.sh <github-org-name>}"
REPO_NAME="moodle503"
MOODLE_BRANCH="MOODLE_503_STABLE"  # Moodle's stable branch for 5.03
WORK_DIR="$(pwd)/${REPO_NAME}"

echo "============================================="
echo "  Setting up ${ORG}/${REPO_NAME}"
echo "============================================="
echo ""

# -----------------------------------------------------------------------------
# 1. Create the GitHub repo
# -----------------------------------------------------------------------------
echo "=== Step 1: Creating GitHub repository ==="

gh repo create "${ORG}/${REPO_NAME}" \
    --private \
    --description "Moodle 5.03 LMS — dev503.sylr.org" \
    --clone 2>/dev/null || {
    echo "  Repo may already exist. Cloning..."
    git clone "https://github.com/${ORG}/${REPO_NAME}.git" "$WORK_DIR" 2>/dev/null || true
}

cd "$WORK_DIR"

# -----------------------------------------------------------------------------
# 2. Add upstream Moodle as a remote
# -----------------------------------------------------------------------------
echo "=== Step 2: Adding upstream Moodle remote ==="

git remote add upstream https://github.com/moodle/moodle.git 2>/dev/null || \
    echo "  Upstream remote already exists"

echo "  Fetching upstream ${MOODLE_BRANCH} (this may take a few minutes)..."
git fetch upstream "$MOODLE_BRANCH" --depth=1

# -----------------------------------------------------------------------------
# 3. Initialize main branch from upstream
# -----------------------------------------------------------------------------
echo "=== Step 3: Initializing main branch from Moodle ${MOODLE_BRANCH} ==="

# Check if main branch already has commits
if git log --oneline -1 &>/dev/null; then
    echo "  Main branch already has commits, skipping initialization"
else
    git checkout -b main "upstream/${MOODLE_BRANCH}"
fi

# -----------------------------------------------------------------------------
# 4. Add common plugins as submodules
# -----------------------------------------------------------------------------
echo "=== Step 4: Adding plugin submodules ==="
echo ""
echo "  Adding commonly used plugins. Edit this section to match your needs."
echo "  Uncomment or add submodules below as needed."
echo ""

# Function to safely add a submodule
add_plugin() {
    local url="$1"
    local path="$2"
    local branch="${3:-main}"

    echo "  Adding: $path"
    if [ -d "$path" ]; then
        echo "    Already exists, skipping"
    else
        git submodule add -b "$branch" "$url" "$path" 2>/dev/null || \
            echo "    Failed to add $path — check URL and try manually"
    fi
}

# --- Uncomment the plugins you want ---

# Attendance
# add_plugin "https://github.com/danmarsden/moodle-mod_attendance.git" "mod/attendance" "main"

# Custom Certificate
# add_plugin "https://github.com/mdjnelson/moodle-mod_customcert.git" "mod/customcert" "MOODLE_404_STABLE"

# Board (Kanban)
# add_plugin "https://github.com/brickfield/moodle-mod_board.git" "mod/board" "main"

# Level Up! (Gamification)
# add_plugin "https://github.com/FMCorz/moodle-block_xp.git" "blocks/xp" "master"

# Boost Union Theme
# add_plugin "https://github.com/moodle-an-hochschulen/moodle-theme_boost_union.git" "theme/boost_union" "main"

# Fordson Theme
# add_plugin "https://github.com/dbnschools/moodle-theme_fordson.git" "theme/fordson" "main"

# Ad-hoc database queries (Reporting)
# add_plugin "https://github.com/moodleou/moodle-report_customsql.git" "report/customsql" "main"

# Generic course format (Tiles, etc.)
# add_plugin "https://github.com/davidherney/moodle-format_onetopic.git" "course/format/onetopic" "main"

echo ""
echo "  To add your own plugins later:"
echo "    git submodule add -b main https://github.com/author/moodle-mod_name.git mod/name"
echo ""

# -----------------------------------------------------------------------------
# 5. Copy deploy infrastructure into the repo
# -----------------------------------------------------------------------------
echo "=== Step 5: Adding deployment infrastructure ==="

# Copy files from the template if they exist alongside this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for f in ".gitignore" ".github/workflows/deploy.yml" "deploy.sh"; do
    SRC="${SCRIPT_DIR}/../${f}"
    DEST="${WORK_DIR}/${f}"
    if [ -f "$SRC" ] && [ ! -f "$DEST" ]; then
        mkdir -p "$(dirname "$DEST")"
        cp "$SRC" "$DEST"
        echo "  Copied: $f"
    fi
done

# Ensure config.php is in .gitignore
if ! grep -q "config.php" .gitignore 2>/dev/null; then
    echo "config.php" >> .gitignore
fi

# -----------------------------------------------------------------------------
# 6. Initial commit and push
# -----------------------------------------------------------------------------
echo "=== Step 6: Committing and pushing ==="

git add -A
git commit -m "Initial Moodle 5.03 setup with deployment infrastructure

- Based on upstream moodle/moodle ${MOODLE_BRANCH}
- GitHub Actions CI/CD pipeline
- Plugin submodule structure
- Nginx + Let's Encrypt config for dev503.sylr.org" || echo "  Nothing to commit"

git push -u origin main

# -----------------------------------------------------------------------------
# 7. Set up branch protection
# -----------------------------------------------------------------------------
echo "=== Step 7: Setting up branch protection ==="

gh api -X PUT "repos/${ORG}/${REPO_NAME}/branches/main/protection" \
    --input - <<'PROTECTION' 2>/dev/null || echo "  Branch protection requires GitHub Pro/Team plan"
{
    "required_status_checks": null,
    "enforce_admins": false,
    "required_pull_request_reviews": null,
    "restrictions": null,
    "allow_force_pushes": false,
    "allow_deletions": false
}
PROTECTION

echo ""
echo "============================================="
echo "  Repository Setup Complete!"
echo "============================================="
echo ""
echo "  Repo:     https://github.com/${ORG}/${REPO_NAME}"
echo "  Branch:   main (tracking upstream ${MOODLE_BRANCH})"
echo "  Upstream: https://github.com/moodle/moodle"
echo ""
echo "  How to update Moodle core in the future:"
echo "  ─────────────────────────────────────────"
echo "  git fetch upstream ${MOODLE_BRANCH}"
echo "  git checkout main"
echo "  git merge upstream/${MOODLE_BRANCH}"
echo "  # Resolve any conflicts in your customizations"
echo "  git push origin main  # triggers auto-deploy"
echo ""
echo "  How to add a plugin:"
echo "  ─────────────────────"
echo "  git submodule add -b main https://github.com/author/moodle-mod_name.git mod/name"
echo "  git commit -m 'Add name plugin'"
echo "  git push"
echo ""
echo "  How to update all plugins:"
echo "  ──────────────────────────"
echo "  git submodule update --remote --merge"
echo "  git add -A && git commit -m 'Update plugins' && git push"
echo ""
echo "  GitHub Secrets needed for deployment:"
echo "  ─────────────────────────────────────"
echo "  EC2_HOST    = Your EC2 Elastic IP"
echo "  EC2_SSH_KEY = Deploy user's private SSH key"
echo "============================================="
