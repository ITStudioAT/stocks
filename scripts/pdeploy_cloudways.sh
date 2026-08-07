#!/usr/bin/env bash
set -Eeuo pipefail

project_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_directory"

maintenance_marker="${project_directory}/storage/framework/cloudways-deploy-maintenance"
maintenance_prepared=false
deployment_handed_off=false
bootstrap_directory=''
repository_url='git@github.com:ITStudioAT/stocks.git'

cleanup_bootstrap_directory() {
    if [ -z "$bootstrap_directory" ] || [ ! -d "$bootstrap_directory" ]; then
        return 0
    fi

    case "$bootstrap_directory" in
        "${project_directory}/storage/framework/cloudways-pdeploy-bootstrap."*)
            rm -rf -- "$bootstrap_directory"
            bootstrap_directory=''
            ;;
        *)
            echo "Refusing to remove an unexpected bootstrap directory: $bootstrap_directory" >&2
            ;;
    esac
}

restore_after_pull_failure() {
    cleanup_bootstrap_directory

    if [ "$maintenance_prepared" != true ] || [ "$deployment_handed_off" = true ]; then
        return
    fi

    if [ ! -f "$maintenance_marker" ] || [ "$(tr -d '\r\n' < "$maintenance_marker")" != prepared ]; then
        echo "The terminal pull failed, but the deployment maintenance marker changed unexpectedly." >&2
        echo "The application remains in maintenance mode for manual inspection." >&2

        return
    fi

    echo "The terminal pull failed; restoring the application from maintenance mode..." >&2

    if php artisan up; then
        rm -f -- "$maintenance_marker"
        maintenance_prepared=false
    fi
}

bootstrap_git_worktree() {
    echo "No Git working tree found; bootstrapping command-line Git access to origin/main..."

    bootstrap_directory="$(mktemp -d "${project_directory}/storage/framework/cloudways-pdeploy-bootstrap.XXXXXX")"

    if ! git clone --depth 1 --branch main "$repository_url" "$bootstrap_directory"; then
        echo "Could not clone $repository_url." >&2
        echo "Give this Cloudways SSH user read access to the private GitHub repository, then run composer pdeploy again." >&2
        echo "A read-only GitHub deploy key is sufficient." >&2
        exit 1
    fi

    STOCKS_CLOUDWAYS_TERMINAL_PULL=true bash scripts/deploy_cloudways.sh --prepare
    maintenance_prepared=true

    echo "Installing origin/main and its Git metadata..."
    git --git-dir="${bootstrap_directory}/.git" --work-tree="$project_directory" reset --hard origin/main
    mv -- "${bootstrap_directory}/.git" "${project_directory}/.git"
    cleanup_bootstrap_directory

    deployment_handed_off=true
    bash scripts/deploy_cloudways.sh

    maintenance_prepared=false
    trap - EXIT

    echo "Cloudways Git bootstrap, pull, and deployment completed successfully."
    exit 0
}

trap restore_after_pull_failure EXIT

for command_name in bash git php flock; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "${command_name} was not found on PATH." >&2
        exit 1
    fi
done

exec 8>storage/framework/cloudways-pdeploy.lock

if ! flock -n 8; then
    echo "Another terminal pull deployment is already running." >&2
    exit 1
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    bootstrap_git_worktree
fi

current_branch="$(git branch --show-current)"

if [ "$current_branch" != main ]; then
    echo "Terminal deployment requires the main branch; current branch: ${current_branch:-detached HEAD}." >&2
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Tracked production files contain local changes; refusing to pull." >&2
    echo "Review git status before deploying." >&2
    exit 1
fi

echo "Fetching origin/main before maintenance mode..."
git fetch origin main

if ! git merge-base --is-ancestor HEAD FETCH_HEAD; then
    echo "Production main has diverged from origin/main; refusing to merge or overwrite files." >&2
    exit 1
fi

STOCKS_CLOUDWAYS_TERMINAL_PULL=true bash scripts/deploy_cloudways.sh --prepare
maintenance_prepared=true

echo "Fast-forwarding production to origin/main..."
git merge --ff-only FETCH_HEAD

deployment_handed_off=true
bash scripts/deploy_cloudways.sh

maintenance_prepared=false
trap - EXIT

echo "Cloudways pull and deployment completed successfully."
