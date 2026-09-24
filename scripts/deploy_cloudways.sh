#!/usr/bin/env bash
set -Eeuo pipefail

project_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_directory"

php scripts/preview-guard.php

if [ ! -f .env ] || [ -L .env ]; then
    echo 'The deployment environment file is missing or is a symbolic link.' >&2
    exit 1
fi

chmod 0640 .env

prepare_only=false

if [ "${1:-}" = "--prepare" ]; then
    prepare_only=true
elif [ "$#" -gt 0 ]; then
    echo "Usage: bash scripts/deploy_cloudways.sh [--prepare]" >&2
    exit 2
fi

maintenance_mode_enabled=false
backend_update_started=false
maintenance_marker="${project_directory}/storage/framework/cloudways-deploy-maintenance"
frontend_release_archive="${project_directory}/deployment/frontend-build.tar.gz"
frontend_release_archive_hash="${project_directory}/deployment/frontend-build.sha256"
frontend_release_marker="${project_directory}/deployment/source-commit"
frontend_release_manifest_path="deployment/source-manifest.sha256"
frontend_release_manifest="${project_directory}/deployment/source-manifest.sha256"
frontend_artifact_directory=""
frontend_backup_directory=""
frontend_artifact_installed=false

prepare_cloudways_pull() {
    if [ -f storage/framework/down ]; then
        if [ ! -f "$maintenance_marker" ]; then
            echo "The application is in maintenance mode, but not because of this deployment workflow." >&2
            echo "Resolve that state before preparing a Cloudways Pull." >&2

            return 1
        fi

        echo "Cloudways deployment maintenance mode is already active."
    else
        printf 'preparing\n' > "$maintenance_marker"

        if ! php artisan down --render="errors::503" --retry=60 --refresh=15; then
            rm -f -- "$maintenance_marker"

            return 1
        fi

        printf 'prepared\n' > "$maintenance_marker"
        echo "Cloudways deployment maintenance mode enabled."
    fi

    if [ "${STOCKS_CLOUDWAYS_TERMINAL_PULL:-false}" = true ]; then
        echo "Cloudways deployment maintenance mode prepared for the terminal pull."
    else
        echo "Now use Cloudways Pull from main, then run composer deploy."
    fi
}

ensure_release_files() {
    if [ ! -f "$frontend_release_archive" ] || [ ! -f "$frontend_release_archive_hash" ] || [ ! -f "$frontend_release_marker" ] || [ ! -f "$frontend_release_manifest" ]; then
        echo "The deployment release is missing." >&2
        echo "Run gitpush locally, then use Cloudways Pull from the main branch again." >&2

        return 1
    fi
}

prepare_frontend_artifact() {
    echo "Verifying the locally built frontend release..."

    if ! php scripts/frontend-release.php verify; then
        echo "The pulled source and frontend release do not belong together." >&2
        echo "Run gitpush locally and use Cloudways Pull again." >&2

        return 1
    fi

    local release_source_commit
    release_source_commit="$(tr -d '\r\n' < "$frontend_release_marker")"

    if [[ ! "$release_source_commit" =~ ^[0-9a-f]{40,64}$ ]]; then
        echo "The Cloudways release source marker is invalid." >&2

        return 1
    fi

    frontend_artifact_directory="$(mktemp -d "${project_directory}/public/.stocks-build.XXXXXX")"

    if ! php scripts/frontend-release.php extract-to "$frontend_artifact_directory"; then
        echo "The validated frontend release archive could not be extracted." >&2

        return 1
    fi

    if [ ! -f "$frontend_artifact_directory/manifest.json" ]; then
        echo "The frontend artifact does not contain public/build/manifest.json." >&2

        return 1
    fi

    if [ -e "$frontend_artifact_directory/deployment-source.txt" ] || [ -L "$frontend_artifact_directory/deployment-source.txt" ]; then
        echo "The frontend artifact contains a public source commit marker." >&2

        return 1
    fi

    echo "Frontend artifact verified for ${release_source_commit}."
}

install_frontend_artifact() {
    echo "Installing the verified frontend artifact..."

    if [ -e public/build ] && [ ! -d public/build ]; then
        echo "public/build exists but is not a directory." >&2

        return 1
    fi

    if [ -d public/hot ] && [ ! -L public/hot ]; then
        echo "public/hot is a directory; refusing to remove it." >&2

        return 1
    fi

    rm -f -- public/hot

    if [ -d public/build ]; then
        frontend_backup_directory="$(mktemp -d "${project_directory}/public/.stocks-build-backup.XXXXXX")"
        rmdir -- "$frontend_backup_directory"
        mv public/build "$frontend_backup_directory"
    fi

    if ! mv "$frontend_artifact_directory" public/build; then
        if [ -n "$frontend_backup_directory" ] && [ -d "$frontend_backup_directory" ]; then
            if mv "$frontend_backup_directory" public/build; then
                frontend_backup_directory=""
            else
                echo "The previous build remains at ${frontend_backup_directory}; restore it manually before serving the application." >&2
            fi
        fi

        echo "Could not install the frontend artifact." >&2

        return 1
    fi

    frontend_artifact_directory=""
    frontend_artifact_installed=true

    if ! php scripts/frontend-release.php validate-build; then
        echo "The installed frontend artifact failed its post-install validation." >&2

        return 1
    fi

    echo "Frontend artifact installed."
}

finalize_frontend_artifact() {
    if [ -z "$frontend_backup_directory" ] || [ ! -d "$frontend_backup_directory" ]; then
        frontend_artifact_installed=false

        return
    fi

    case "$frontend_backup_directory" in
        "${project_directory}"/public/.stocks-build-backup.*)
            rm -rf -- "$frontend_backup_directory"
            frontend_backup_directory=""
            frontend_artifact_installed=false
            ;;
        *)
            echo "Refusing to remove unexpected frontend backup directory: ${frontend_backup_directory}" >&2

            return 1
            ;;
    esac
}

rollback_frontend_artifact() {
    if [ "$frontend_artifact_installed" != true ]; then
        return
    fi

    echo "Restoring the previous frontend build..." >&2

    if [ -d public/build ]; then
        rm -rf -- public/build
    fi

    if [ -z "$frontend_backup_directory" ] || [ ! -d "$frontend_backup_directory" ]; then
        frontend_artifact_installed=false

        return
    fi

    if mv "$frontend_backup_directory" public/build; then
        frontend_backup_directory=""
        frontend_artifact_installed=false

        return
    fi

    echo "Could not restore the previous frontend from ${frontend_backup_directory}." >&2
}

cleanup_frontend_artifact() {
    if [ -z "$frontend_artifact_directory" ] || [ ! -d "$frontend_artifact_directory" ]; then
        return
    fi

    case "$frontend_artifact_directory" in
        "${project_directory}"/public/.stocks-build.*)
            rm -rf -- "$frontend_artifact_directory"
            frontend_artifact_directory=""
            ;;
        *)
            echo "Refusing to remove unexpected frontend artifact directory: ${frontend_artifact_directory}" >&2
            ;;
    esac
}

restore_application() {
    rollback_frontend_artifact

    if [ "$maintenance_mode_enabled" = true ]; then
        if [ "$backend_update_started" = true ]; then
            echo "Deployment failed after application changes began." >&2
            echo "The application remains in maintenance mode. Fix the error, rerun composer deploy, then use php artisan up only after success." >&2
        else
            echo "Restoring the application from maintenance mode..."
            php artisan up || true
        fi
    fi

    cleanup_frontend_artifact
}

trap restore_application EXIT

for command_name in php composer tar mktemp flock; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "${command_name} was not found on PATH." >&2
        exit 1
    fi
done

exec 9>storage/framework/cloudways-deploy.lock

if ! flock -n 9; then
    echo "Another Cloudways deployment is already running." >&2
    exit 1
fi

if [ "$prepare_only" = true ]; then
    prepare_cloudways_pull
    trap - EXIT

    exit 0
fi

echo "Verifying Cloudways PHP platform requirements..."
composer check-platform-reqs --lock --no-dev --no-interaction

if [ -f storage/framework/down ]; then
    if [ ! -f "$maintenance_marker" ]; then
        echo "The application is in maintenance mode, but not because of this deployment workflow." >&2
        echo "Resolve that state before deploying." >&2
        exit 1
    fi

    maintenance_mode_enabled=true
    backend_update_started=true
    echo "Resuming the interrupted Cloudways deployment."
elif [ -f "$maintenance_marker" ]; then
    rm -f -- "$maintenance_marker"
fi

ensure_release_files

if [ "$maintenance_mode_enabled" != true ]; then
    printf 'preparing\n' > "$maintenance_marker"

    if ! php artisan down --render="errors::503" --retry=60 --refresh=15; then
        rm -f -- "$maintenance_marker"
        exit 1
    fi

    maintenance_mode_enabled=true
fi

backend_update_started=true
printf 'backend-started\n' > "$maintenance_marker"

echo "Pruning stale source files preserved by Cloudways Pull..."
php scripts/source-manifest.php prune-unlisted "$frontend_release_manifest_path"
prepare_frontend_artifact

composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction
composer check-platform-reqs --no-dev --no-interaction

install_frontend_artifact
php artisan app:update --production --no-interaction --skip-composer --skip-npm --skip-build

finalize_frontend_artifact
php artisan up
maintenance_mode_enabled=false
backend_update_started=false
rm -f -- "$maintenance_marker"
cleanup_frontend_artifact
trap - EXIT

echo "Cloudways deployment completed successfully."
