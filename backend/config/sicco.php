<?php

return [

    /*
    | Contraseña temporal de los usuarios de la demo (UsuarioDemoProduccionSeeder).
    | Se lee aquí y no con env() en el seeder porque en el servidor la
    | configuración está cacheada y env() devuelve null.
    */
    'demo_password' => env('DEMO_PASSWORD'),

    /*
    | Los elementos estructurales y las resistencias f'c están en las tablas `elementos_estructurales`
    | y `resistencias` (se mantienen desde «Catálogos»).
    */
    'partidas' => [
        // Por encima de este % el desperdicio se registra solo con confirmación.
        'desperdicio_confirmar' => 15,
    ],

];
