<?php

declare(strict_types=1);

return [
    'labels' => [
        'token' => 'API-Token',
        'reachable' => 'Erreichbar & authentifiziert',
        'mode' => 'Modus',
        'abilities' => 'Token-Berechtigungen',
        'webhook' => 'Webhook',
        'migrations' => 'Spiegeltabellen',
        'webhook_delivery' => 'Webhook-Zustellung',
    ],

    'token' => [
        'missing' => 'VORGIO_TOKEN ist nicht gesetzt.',
        'set' => 'VORGIO_TOKEN ist gesetzt.',
    ],

    'reachable' => [
        'ok' => 'Vorgio hat den Token akzeptiert.',
        'rejected' => 'Vorgio hat die Anfrage abgelehnt (HTTP :status): :message',
        'unreachable' => 'Vorgio konnte nicht erreicht werden: :message',
    ],

    'mode' => [
        'live' => 'Token ist im LIVE-Modus.',
        'test' => 'Token ist im TEST-Modus — Test-Rechnungen werden nicht per E-Mail versendet und nicht abgerechnet. Verwende für den Produktivbetrieb einen LIVE-Token.',
        'unknown' => 'Unbekannter Modus.',
    ],

    'abilities' => [
        'ok' => 'Alle erforderlichen Berechtigungen sind erteilt.',
        'missing' => 'Fehlende Berechtigungen: :abilities. Erstelle den Token mit dem passenden Preset neu.',
    ],

    'webhook' => [
        'no_secret' => 'VORGIO_WEBHOOK_SECRET ist nicht gesetzt — diese App kann keine Webhooks empfangen.',
        'not_registered' => 'Kein Webhook für :url registriert. Lege einen in deinen Vorgio-Team-Einstellungen an.',
        'inactive' => 'Der Webhook für :url ist inaktiv (vermutlich nach wiederholten Zustellfehlern automatisch deaktiviert). Aktiviere ihn erneut.',
        'missing_events' => 'Webhook ist registriert, aber nicht für folgende Ereignisse abonniert: :events.',
        'ok' => 'Aktiver Webhook für :url mit den erforderlichen Ereignissen registriert.',
    ],

    'migrations' => [
        'ok' => 'Alle Vorgio-Spiegeltabellen sind vorhanden.',
        'missing' => 'Fehlende Tabellen: :tables. Führe `php artisan migrate` aus.',
    ],

    'webhook_delivery' => [
        'request_failed' => 'Test-Webhook-Anfrage fehlgeschlagen: :message',
        'none_received' => 'Kein aktiver Webhook hat das Test-Ereignis empfangen.',
        'rejected_status' => 'Endpunkt :url hat das Test-Ereignis nicht akzeptiert (HTTP :status). Prüfe die URL und ob VORGIO_WEBHOOK_SECRET übereinstimmt.',
        'rejected_error' => 'Endpunkt :url hat das Test-Ereignis nicht akzeptiert: :error Prüfe die URL und ob VORGIO_WEBHOOK_SECRET übereinstimmt.',
        'rejected_unknown' => 'Endpunkt :url hat das Test-Ereignis nicht akzeptiert. Prüfe die URL und ob VORGIO_WEBHOOK_SECRET übereinstimmt.',
        'ok' => 'Dein Endpunkt hat ein signiertes Test-Ereignis empfangen und verifiziert.',
    ],
];
