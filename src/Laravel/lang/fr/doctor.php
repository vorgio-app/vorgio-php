<?php

declare(strict_types=1);

return [
    'labels' => [
        'token' => 'Jeton d’API',
        'reachable' => 'Accessible et authentifié',
        'mode' => 'Mode',
        'abilities' => 'Autorisations du jeton',
        'webhook' => 'Webhook',
        'migrations' => 'Tables miroir',
        'webhook_delivery' => 'Livraison du webhook',
    ],

    'token' => [
        'missing' => 'VORGIO_TOKEN n’est pas défini.',
        'set' => 'VORGIO_TOKEN est défini.',
    ],

    'reachable' => [
        'ok' => 'Vorgio a accepté le jeton.',
        'rejected' => 'Vorgio a rejeté la requête (HTTP :status) : :message',
        'unreachable' => 'Impossible de joindre Vorgio : :message',
    ],

    'mode' => [
        'live' => 'Le jeton est en mode LIVE.',
        'test' => 'Le jeton est en mode TEST — les factures de test ne sont pas envoyées par e-mail et ne sont pas facturées. Utilisez un jeton LIVE en production.',
        'unknown' => 'Mode inconnu.',
    ],

    'abilities' => [
        'ok' => 'Toutes les autorisations requises sont accordées.',
        'missing' => 'Autorisations manquantes : :abilities. Régénérez le jeton avec le préréglage correspondant.',
    ],

    'webhook' => [
        'no_secret' => 'VORGIO_WEBHOOK_SECRET n’est pas défini — cette application ne peut pas recevoir de webhooks.',
        'not_registered' => 'Aucun webhook enregistré pour :url. Ajoutez-en un dans les paramètres de votre équipe Vorgio.',
        'inactive' => 'Le webhook pour :url est inactif (probablement désactivé automatiquement après des échecs de livraison répétés). Réactivez-le.',
        'missing_events' => 'Le webhook est enregistré mais n’est pas abonné à : :events.',
        'ok' => 'Webhook actif enregistré pour :url avec les événements requis.',
    ],

    'migrations' => [
        'ok' => 'Toutes les tables miroir Vorgio existent.',
        'missing' => 'Tables manquantes : :tables. Exécutez `php artisan migrate`.',
    ],

    'webhook_delivery' => [
        'request_failed' => 'Échec de la requête de webhook de test : :message',
        'none_received' => 'Aucun webhook actif n’a reçu l’événement de test.',
        'rejected_status' => 'Le point de terminaison :url n’a pas accepté l’événement de test (HTTP :status). Vérifiez l’URL et que VORGIO_WEBHOOK_SECRET correspond.',
        'rejected_error' => 'Le point de terminaison :url n’a pas accepté l’événement de test : :error Vérifiez l’URL et que VORGIO_WEBHOOK_SECRET correspond.',
        'rejected_unknown' => 'Le point de terminaison :url n’a pas accepté l’événement de test. Vérifiez l’URL et que VORGIO_WEBHOOK_SECRET correspond.',
        'ok' => 'Votre point de terminaison a reçu et vérifié un événement de test signé.',
    ],
];
