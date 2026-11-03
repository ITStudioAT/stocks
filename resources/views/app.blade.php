<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('stocks.name') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/deployment-notice.css">
        <script type="module" src="/deployment-notice.js"></script>
    </head>
    <body>
        <div id="app"></div>
        <x-deployment-notice />
    </body>
</html>
