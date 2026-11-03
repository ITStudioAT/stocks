@props(['maintenance' => false])

<dialog
    class="stocks-deployment-notice"
    lang="de"
    data-deployment-notice
    data-state="{{ $maintenance ? 'maintenance' : 'idle' }}"
    aria-labelledby="stocks-deployment-title"
    aria-describedby="stocks-deployment-description"
    @if($maintenance) open @endif
>
    <div role="status" aria-live="polite" aria-atomic="true">
        <p class="stocks-deployment-notice__label">Stocks · Systemstatus</p>
        <h1 id="stocks-deployment-title">{{ $maintenance ? 'Stocks wird aktualisiert' : '' }}</h1>
        <p id="stocks-deployment-description">{{ $maintenance ? 'Bitte lassen Sie diese Seite geöffnet. Sobald Stocks wieder verfügbar ist, können Sie die Seite neu laden.' : '' }}</p>
    </div>
    <div class="stocks-deployment-notice__actions">
        <button type="button" data-deployment-reload hidden>Jetzt neu laden</button>
        <button type="button" data-deployment-dismiss hidden>Später</button>
    </div>
    @if($maintenance)
        <noscript><p>Bitte laden Sie diese Seite in wenigen Minuten erneut.</p></noscript>
    @endif
</dialog>
