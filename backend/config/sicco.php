<?php

return [

    /*
    | Contraseña temporal de los usuarios de la demo (UsuarioDemoProduccionSeeder).
    | Se lee aquí y no con env() en el seeder porque en el servidor la
    | configuración está cacheada y env() devuelve null.
    */
    'demo_password' => env('DEMO_PASSWORD'),

    /*
    | Elementos estructurales de la partida (CU04). Son los mismos del frontend (src/catalogos/partidas.ts):
    | la tesis los muestra como lista sin dar los valores, así que siguen siendo los de la maqueta.
    | Las resistencias f'c están en la tabla `resistencias`.
    */
    'partidas' => [
        'elementos' => [
            'Zapata', 'Cimiento corrido', 'Columna', 'Placa', 'Viga',
            'Losa aligerada', 'Losa maciza', 'Escalera',
        ],
        // Por encima de este % el desperdicio se registra solo con confirmación.
        'desperdicio_confirmar' => 15,
    ],

];
