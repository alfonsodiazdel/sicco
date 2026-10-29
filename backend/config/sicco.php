<?php

return [

    /*
    | Contraseña temporal de los usuarios de la demo (UsuarioDemoProduccionSeeder).
    | Se lee aquí y no con env() en el seeder porque en el servidor la
    | configuración está cacheada y env() devuelve null.
    */
    'demo_password' => env('DEMO_PASSWORD'),

];
