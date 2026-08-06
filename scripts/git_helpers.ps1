function Invoke-StocksCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Description,

        [Parameter(Mandatory = $true)]
        [scriptblock]$Command
    )

    Write-Host $Description -ForegroundColor Cyan
    & $Command

    if ($LASTEXITCODE -ne 0) {
        throw "$Description failed with exit code $LASTEXITCODE."
    }
}

function Start-StocksCheckProcess {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Command,

        [Parameter(Mandatory = $true)]
        [string]$OutputPath,

        [Parameter(Mandatory = $true)]
        [string]$ErrorPath,

        [Parameter(Mandatory = $true)]
        [string]$WorkingDirectory
    )

    $commandShell = $env:COMSPEC
    if (-not $commandShell) {
        $commandShell = 'cmd.exe'
    }

    $redirectedCommand = '(' + $Command + ') 1>"' + $OutputPath + '" 2>"' + $ErrorPath + '"'
    $commandArguments = '/d /s /c "' + $redirectedCommand + '"'

    Start-Process `
        -FilePath $commandShell `
        -ArgumentList $commandArguments `
        -WorkingDirectory $WorkingDirectory `
        -WindowStyle Hidden `
        -PassThru
}

function Invoke-StocksReleaseChecks {
    param(
        [Parameter(Mandatory = $false)]
        [switch]$Full
    )

    $temporaryPrefix = Join-Path ([System.IO.Path]::GetTempPath()) ("stocks-release-" + [guid]::NewGuid().ToString('N'))
    $phpOutput = "$temporaryPrefix-php.out"
    $phpError = "$temporaryPrefix-php.err"
    $frontendOutput = "$temporaryPrefix-frontend.out"
    $frontendError = "$temporaryPrefix-frontend.err"

    $frontendCommand = if ($Full) { 'npm run test:frontend && npm run build' } else { 'npm run build' }
    $scope = if ($Full) { 'full tests and release build' } else { 'release build (tests already completed during development)' }
    Write-Host "Running $scope..." -ForegroundColor Cyan

    $workingDirectory = (Get-Location).Path
    $frontendProcess = Start-StocksCheckProcess `
        -Command $frontendCommand `
        -OutputPath $frontendOutput `
        -ErrorPath $frontendError `
        -WorkingDirectory $workingDirectory

    $processes = @($frontendProcess)
    $checks = @(
        @{ Name = 'Frontend release build'; Process = $frontendProcess; Output = $frontendOutput; Error = $frontendError }
    )

    if ($Full) {
        $php = (Get-Command php -ErrorAction Stop).Source
        $phpCommand = '"' + $php + '" artisan test --compact'
        $phpProcess = Start-StocksCheckProcess `
            -Command $phpCommand `
            -OutputPath $phpOutput `
            -ErrorPath $phpError `
            -WorkingDirectory $workingDirectory
        $processes += @($phpProcess)
        $checks += @(
            @{ Name = 'PHP tests'; Process = $phpProcess; Output = $phpOutput; Error = $phpError }
        )
    }

    $startedAt = Get-Date

    while ($processes | Where-Object { -not $_.HasExited }) {
        $elapsed = [math]::Floor(((Get-Date) - $startedAt).TotalSeconds)
        Write-Host "  Local release checks are running ($elapsed seconds)..." -ForegroundColor DarkGray
        Start-Sleep -Seconds 10
    }

    foreach ($process in $processes) {
        $process.WaitForExit()
        $process.Refresh()
    }

    $failed = $false

    foreach ($check in $checks) {
        if ($check.Process.ExitCode -eq 0) {
            Write-Host ("  OK: " + $check.Name) -ForegroundColor Green
            continue
        }

        $failed = $true
        Write-Host ("  FAILED: " + $check.Name) -ForegroundColor Red

        if (Test-Path -LiteralPath $check.Output) {
            Get-Content -LiteralPath $check.Output -Encoding UTF8
        }

        if (Test-Path -LiteralPath $check.Error) {
            Get-Content -LiteralPath $check.Error -Encoding UTF8
        }
    }

    foreach ($path in @($phpOutput, $phpError, $frontendOutput, $frontendError)) {
        if (Test-Path -LiteralPath $path) {
            [System.IO.File]::Delete($path)
        }
    }

    if ($failed) {
        throw 'Local release checks failed. Nothing was pushed.'
    }
}

function Wait-StocksCi {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Commit
    )

    if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
        Write-Host 'GitHub CLI is unavailable; CI continues in GitHub Actions.' -ForegroundColor Yellow
        return
    }

    Write-Host 'Waiting for GitHub CI...' -ForegroundColor Cyan
    $run = $null

    for ($attempt = 1; $attempt -le 30; $attempt++) {
        $json = gh run list --workflow CI --branch main --commit $Commit --event push --limit 1 --json databaseId,url,status,conclusion

        if ($LASTEXITCODE -eq 0 -and $json) {
            $run = $json | ConvertFrom-Json | Select-Object -First 1
        }

        if ($run) {
            break
        }

        Start-Sleep -Seconds 2
    }

    if (-not $run) {
        Write-Host 'The release is pushed, but its GitHub run was not found yet.' -ForegroundColor Yellow
        return
    }

    gh run watch $run.databaseId --exit-status --interval 10

    if ($LASTEXITCODE -ne 0) {
        throw "GitHub CI failed: $($run.url)"
    }

    Write-Host "GitHub CI verified the release: $($run.url)" -ForegroundColor Green
}

function gitpush {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$message,

        [Parameter(Mandatory = $false)]
        [string]$version,

        [Parameter(Mandatory = $false)]
        [switch]$WaitForCI,

        [Parameter(Mandatory = $false)]
        [switch]$Full
    )

    try {
        $branch = git branch --show-current

        if ($LASTEXITCODE -ne 0 -or $branch -ne 'main') {
            throw "gitpush only publishes the main branch. Current branch: $branch"
        }

        Invoke-StocksCommand 'Synchronizing main before the release...' {
            git pull --rebase --autostash origin main
        }

        Invoke-StocksCommand 'Preparing local dependencies...' {
            php scripts/update.php --target=local --prepare
        }

        Invoke-StocksCommand 'Formatting changed PHP files...' {
            php vendor/bin/pint --dirty --format agent
        }

        Invoke-StocksCommand 'Checking UTF-8 source files...' {
            php scripts/check-encoding.php
        }

        $sourceChanges = git status --porcelain --untracked-files=all | Where-Object {
            $_ -notmatch '^.. deployment/(frontend-build\.(?:tar\.gz|sha256)|source-commit|source-manifest\.sha256)$'
        }
        $localHead = git rev-parse HEAD
        $remoteHead = git rev-parse origin/main
        $sourceCommit = $null
        $releaseCommit = $null

        if (-not $sourceChanges) {
            if ($localHead -eq $remoteHead) {
                Write-Host 'No source changes to publish.' -ForegroundColor Yellow
                return
            }

            git merge-base --is-ancestor $remoteHead $localHead
            if ($LASTEXITCODE -ne 0) {
                throw 'Local main does not continue origin/main. Synchronize the branch before publishing.'
            }

            $parentCommit = git rev-parse "$localHead^"
            $releaseFilesExist =
                (Test-Path -LiteralPath 'deployment/frontend-build.tar.gz') -and
                (Test-Path -LiteralPath 'deployment/frontend-build.sha256') -and
                (Test-Path -LiteralPath 'deployment/source-commit') -and
                (Test-Path -LiteralPath 'deployment/source-manifest.sha256')

            if ($releaseFilesExist) {
                & php scripts/frontend-release.php verify $parentCommit *> $null

                if ($LASTEXITCODE -eq 0) {
                    $releaseCommit = $localHead
                    Write-Host "Resuming the completed local release $releaseCommit." -ForegroundColor Cyan
                }
            }

            if (-not $releaseCommit) {
                $sourceCommit = $localHead
                Write-Host "Completing the unpushed source commit $sourceCommit." -ForegroundColor Cyan
            }
        }

        if (-not $releaseCommit) {
            Invoke-StocksReleaseChecks -Full:$Full

            $sourceChanges = git status --porcelain --untracked-files=all | Where-Object {
                $_ -notmatch '^.. deployment/(frontend-build\.(?:tar\.gz|sha256)|source-commit|source-manifest\.sha256)$'
            }

            if ($sourceChanges) {
                foreach ($releasePath in @(
                    'deployment/frontend-build.tar.gz',
                    'deployment/frontend-build.sha256',
                    'deployment/source-commit',
                    'deployment/source-manifest.sha256'
                )) {
                    if (Test-Path -LiteralPath $releasePath) {
                        [System.IO.File]::Delete((Resolve-Path -LiteralPath $releasePath).Path)
                    }
                }

                git add -A
                if ($LASTEXITCODE -ne 0) {
                    throw 'Could not stage the source changes.'
                }

                git commit -m $message
                if ($LASTEXITCODE -ne 0) {
                    throw 'Could not create the source commit.'
                }

                $postCommitChanges = git status --porcelain --untracked-files=all | Where-Object {
                    $_ -notmatch '^.. deployment/(frontend-build\.(?:tar\.gz|sha256)|source-commit|source-manifest\.sha256)$'
                }

                if ($postCommitChanges) {
                    throw 'The source commit left additional changes in the worktree. Review them before publishing.'
                }

                $sourceCommit = git rev-parse HEAD
                Write-Host "Source commit: $sourceCommit" -ForegroundColor Cyan
            }

            if (-not $sourceCommit) {
                $sourceCommit = git rev-parse HEAD
            }

            Invoke-StocksCommand 'Creating the commit-bound frontend release...' {
                php scripts/frontend-release.php create $sourceCommit
            }

            Invoke-StocksCommand 'Verifying the commit-bound frontend release...' {
                php scripts/frontend-release.php verify $sourceCommit
            }

            git add -f deployment/frontend-build.tar.gz deployment/frontend-build.sha256 deployment/source-commit deployment/source-manifest.sha256
            if ($LASTEXITCODE -ne 0) {
                throw 'Could not stage the deployment release.'
            }

            git commit -m "Build deployment release for $sourceCommit"
            if ($LASTEXITCODE -ne 0) {
                throw 'Could not create the deployment release commit.'
            }

            $releaseCommit = git rev-parse HEAD
        }

        $pushArguments = @('--atomic', 'origin', 'HEAD:main')

        if ($version) {
            $tag = "v$version"
            $existingTagCommit = git rev-list -n 1 $tag 2>$null

            if ($existingTagCommit) {
                if ($existingTagCommit -ne $releaseCommit) {
                    throw "Tag $tag already belongs to another commit."
                }

                Write-Host "Reusing local tag $tag." -ForegroundColor Cyan
            }
            else {
                git tag -a $tag -m ("Version {0}: {1}" -f $version, $message)
                if ($LASTEXITCODE -ne 0) {
                    throw "Could not create tag $tag."
                }
            }

            $pushArguments += "refs/tags/$tag"
        }

        Write-Host 'Pushing the complete release to main...' -ForegroundColor Cyan
        git push @pushArguments
        if ($LASTEXITCODE -ne 0) {
            throw 'The atomic release push failed. Nothing changed on GitHub.'
        }

        if ($WaitForCI) {
            Wait-StocksCi -Commit $releaseCommit
        }

        Write-Host ''
        Write-Host 'READY.' -ForegroundColor Green
        Write-Host 'Windows PCs may pull main and run: composer deploy' -ForegroundColor Green
        Write-Host 'Cloudways: run composer deploy:prepare, Pull main, then run composer deploy' -ForegroundColor Green

        if (-not $WaitForCI) {
            Write-Host 'GitHub is checking the release in the background.' -ForegroundColor DarkGray
        }
    }
    catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
        throw
    }
}
