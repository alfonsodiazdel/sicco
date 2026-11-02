<?php

return [

    /*
    | Contraseña temporal de los usuarios de la demo (UsuarioDemoProduccionSeeder).
    | Se lee aquí y no con env() en el seeder porque en el servidor la
    | configuración está cacheada y env() devuelve null.
    */
    'demo_password' => env('DEMO_PASSWORD'),

    /*
    | Listas de la partida (CU04). Son las mismas del frontend (src/catalogos/partidas.ts):
    | la tesis las muestra como lista sin dar los valores, así que siguen siendo los de la maqueta.
    */
    'partidas' => [
        'elementos' => [
            'Zapata', 'Cimiento corrido', 'Columna', 'Placa', 'Viga',
            'Losa aligerada', 'Losa maciza', 'Escalera',
        ],
        'resistencias' => ["f'c 175 kg/cm²", "f'c 210 kg/cm²", "f'c 280 kg/cm²", "f'c 350 kg/cm²"],
        // Por encima de este % el desperdicio se registra solo con confirmación.
        'desperdicio_confirmar' => 15,
    ],

];
