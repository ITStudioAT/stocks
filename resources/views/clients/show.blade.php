<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $client->name }} | {{ config('app.name') }}</title>
        <style>
            {!! $homepageColorCss !!}

            :root {
                color-scheme: light;
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            body {
                min-height: 100vh;
                margin: 0;
                display: grid;
                place-items: center;
                padding: 24px;
                background: var(--color-page-bg);
                color: var(--color-text);
            }

            main {
                width: min(100%, 920px);
            }

            .signature {
                display: inline-flex;
                margin-bottom: 24px;
                padding: 8px 12px;
                border: 1px solid var(--color-card-border);
                border-radius: 999px;
                color: var(--color-accent);
                font-size: 0.82rem;
                font-weight: 700;
            }

            h1 {
                max-width: 800px;
                margin: 0 0 18px;
                color: var(--color-heading);
                font-size: clamp(2.5rem, 7vw, 5.5rem);
                line-height: 0.95;
                letter-spacing: 0;
            }

            .subheadline {
                max-width: 700px;
                margin: 0 0 24px;
                color: var(--color-heading-accent);
                font-size: clamp(1.2rem, 2vw, 1.55rem);
                line-height: 1.45;
            }

            .body {
                max-width: 720px;
                margin: 0;
                color: var(--color-text-muted);
                font-size: 1.05rem;
                line-height: 1.7;
            }
        </style>
    </head>
    <body>
        <main>
            <span class="signature">/{{ $client->signature }}</span>
            <h1>{{ $client->headline }}</h1>
            @if ($client->subheadline)
                <p class="subheadline">{{ $client->subheadline }}</p>
            @endif
            @if ($client->body)
                <p class="body">{{ $client->body }}</p>
            @endif
        </main>
    </body>
</html>
