#!/usr/bin/env bash
set -Eeuo pipefail

project_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_directory"

maintenance_mode_enabled=false
backend_update_started=false
frontend_release_archive="${project_directory}/deployment/frontend-build.tar.gz"
frontend_release_marker="${project_directory}/deployment/source-commit"
frontend_release_manifest="${project_directory}/deployment/source-manifest.sha256"
frontend_artifact_directory=""
frontend_backup_directory=""

prepare_frontend_artifact() {
    echo "Verifying the locally built frontend release..."

    if [ ! -f "$frontend_release_archive" ] || [ ! -f "$frontend_release_marker" ] || [ ! -f "$frontend_release_manifest" ]; then
        echo "The deployment release is missing." >&2
        echo "Run gitpush locally, then use Cloudways Pull from the main branch again." >&2

        return 1
    fi

    if ! php scripts/frontend-release.php verify; then
        echo "The pulled source and frontend release do not belong together." >&2
        echo "Run gitpush locally and use Cloudways Pull again." >&2

        return 1
    fi

    local release_source_commit
    local artifact_source_commit

    release_source_commit="$(tr -d '\r\n' < "$frontend_release_marker")"

    if [[ ! "$release_source_commit" =~ ^[0-9a-f]{40,64}$ ]]; then
        echo "The Cloudways release source marker is invalid." >&2

        return 1
    fi

    frontend_artifact_directory="$(mktemp -d "${project_directory}/public/.stocks-build.XXXXXX")"

    if ! tar -xzf "$frontend_release_archive" -C "$frontend_artifact_directory"; then
        echo "The frontend release archive could not be extracted." >&2

        return 1
    fi

    if [ ! -f "$frontend_artifact_directory/manifest.json" ]; then
        echo "The frontend artifact does not contain public/build/manifest.json." >&2

        return 1
    fi

    if [ ! -f "$frontend_artifact_directory/deployment-source.txt" ]; then
        echo "The frontend artifact source marker is missing." >&2

        return 1
    fi

    artifact_source_commit="$(tr -d '\r\n' < "$frontend_artifact_directory/deployment-source.txt")"

    if [ "$artifact_source_commit" != "$release_source_commit" ]; then
        echo "The frontend artifact was built for ${artifact_source_commit}, but the Cloudways release contains ${release_source_commit}." >&2

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
    echo "Frontend artifact installed."
}

finalize_frontend_artifact() {
    if [ -z "$frontend_backup_directory" ] || [ ! -d "$frontend_backup_directory" ]; then
        return
    fi

    case "$frontend_backup_directory" in
        "${project_directory}"/public/.stocks-build-backup.*)
            rm -rf -- "$frontend_backup_directory"
            frontend_backup_directory=""
            ;;
        *)
            echo "Refusing to remove unexpected frontend backup directory: ${frontend_backup_directory}" >&2

            return 1
            ;;
    esac
}

rollback_frontend_artifact() {
    if [ -z "$frontend_backup_directory" ] || [ ! -d "$frontend_backup_directory" ]; then
        return
    fi

    echo "Restoring the previous frontend build..." >&2

    if [ -d public/build ]; then
        rm -rf -- public/build
    fi

    if mv "$frontend_backup_directory" public/build; then
        frontend_backup_directory=""

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

if [ -f storage/framework/down ]; then
    echo "The application was already in maintenance mode. Resolve that state before deploying." >&2
    exit 1
fi

prepare_frontend_artifact

php artisan down --render="errors::503" --retry=60 --refresh=15
maintenance_mode_enabled=true
backend_update_started=true

composer install \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction

install_frontend_artifact
php artisan app:update --no-interaction --skip-composer --skip-npm --skip-build

php artisan up
maintenance_mode_enabled=false
backend_update_started=false
finalize_frontend_artifact
cleanup_frontend_artifact
trap - EXIT

echo "Cloudways deployment completed successfully."
