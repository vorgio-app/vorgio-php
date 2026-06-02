<?php

declare(strict_types=1);

return [
    'labels' => [
        'token' => 'Token de API',
        'reachable' => 'Accesible y autenticado',
        'mode' => 'Modo',
        'abilities' => 'Permisos del token',
        'webhook' => 'Webhook',
        'migrations' => 'Tablas espejo',
        'webhook_delivery' => 'Entrega de webhook',
    ],

    'token' => [
        'missing' => 'VORGIO_TOKEN no está configurado.',
        'set' => 'VORGIO_TOKEN está configurado.',
    ],

    'reachable' => [
        'ok' => 'Vorgio aceptó el token.',
        'rejected' => 'Vorgio rechazó la solicitud (HTTP :status): :message',
        'unreachable' => 'No se pudo conectar con Vorgio: :message',
    ],

    'mode' => [
        'live' => 'El token está en modo LIVE.',
        'test' => 'El token está en modo TEST — las facturas de prueba no se envían por correo ni se cobran. Usa un token LIVE en producción.',
        'unknown' => 'Modo desconocido.',
    ],

    'abilities' => [
        'ok' => 'Se han concedido todos los permisos requeridos.',
        'missing' => 'Permisos faltantes: :abilities. Vuelve a emitir el token con el preajuste correspondiente.',
    ],

    'webhook' => [
        'no_secret' => 'VORGIO_WEBHOOK_SECRET no está configurado — esta aplicación no puede recibir webhooks.',
        'not_registered' => 'No hay ningún webhook registrado para :url. Añade uno en la configuración de tu equipo de Vorgio.',
        'inactive' => 'El webhook para :url está inactivo (probablemente desactivado automáticamente tras fallos de entrega repetidos). Vuelve a activarlo.',
        'missing_events' => 'El webhook está registrado pero no está suscrito a: :events.',
        'ok' => 'Webhook activo registrado para :url con los eventos requeridos.',
    ],

    'migrations' => [
        'ok' => 'Todas las tablas espejo de Vorgio existen.',
        'missing' => 'Tablas faltantes: :tables. Ejecuta `php artisan migrate`.',
    ],

    'webhook_delivery' => [
        'request_failed' => 'La solicitud de webhook de prueba falló: :message',
        'none_received' => 'Ningún webhook activo recibió el evento de prueba.',
        'rejected_status' => 'El endpoint :url no aceptó el evento de prueba (HTTP :status). Comprueba la URL y que VORGIO_WEBHOOK_SECRET coincida.',
        'rejected_error' => 'El endpoint :url no aceptó el evento de prueba: :error Comprueba la URL y que VORGIO_WEBHOOK_SECRET coincida.',
        'rejected_unknown' => 'El endpoint :url no aceptó el evento de prueba. Comprueba la URL y que VORGIO_WEBHOOK_SECRET coincida.',
        'ok' => 'Tu endpoint recibió y verificó un evento de prueba firmado.',
    ],
];
