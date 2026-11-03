# Validaciones de formularios — SICCO

Reglas de validación de cada formulario, en el front y en el backend. Cada regla nueva se escribe
aquí en el mismo commit que la implementa.

## 1. Principio: el front ayuda, el backend protege

- **Front (Vue + vee-validate + zod):** solo es para la experiencia de uso. Avisa del error debajo
  del campo antes de enviar y bloquea las teclas que no corresponden. Cualquiera puede saltárselo
  con las herramientas del navegador o llamando a la API directamente.
- **Backend (Laravel, un `FormRequest` por acción):** es la validación que protege. Todo dato que
  entra se valida en el servidor con lista de lo permitido (tipo, largo, formato y rango), aunque el
  front ya lo haya validado. Si falla, responde 422 y el front pinta los mensajes en cada campo
  (`setErrors`, como ya hacen `LoginView.vue` y `CambiarPasswordView.vue`).
- **Base de datos:** es la última barrera (`NOT NULL`, largo de columna, `UNIQUE`, claves foráneas).
  No reemplaza al `FormRequest`: si un dato falla en la base, el usuario ve un error 500 en vez del
  mensaje del campo.
- **Al mostrar datos:** se escapan siempre. En Vue, `{{ }}` ya escapa. Prohibido usar `v-html` con
  datos del usuario.

Referencias: OWASP Input Validation Cheat Sheet (validar en el servidor, con lista de lo permitido,
el largo y el rango) y OWASP ASVS 5.0, capítulos V2 (validación y lógica de negocio) y V1
(codificación al mostrar datos).

## 2. Reglas por tipo de dato

| Tipo | Control en el front | Por qué | Regla en Laravel |
|---|---|---|---|
| Cantidad (m³, S/, %) | `InputNumber` de PrimeVue con dos decimales como máximo y sin negativos | Teclado numérico; la tesis pide dos decimales | `numeric\|gt:0` (o `min:0` en el desperdicio) + `decimal:0,2` |
| Texto libre | `InputText` con `maxlength` igual al de la columna | Evita errores de la base | `string\|max:n` |
| Correo | `InputText` con `type="email"` + comprobación de dominio con punto en el esquema | El navegador acepta `x@y` | `email:rfc\|max:255`, guardado en minúsculas |
| Fecha | `DatePicker` de PrimeVue; el fin, con fecha mínima = inicio + 1 día | Calendario | `date`; el fin, `after:fecha_inicio` |
| Lista (rol, ingeniero, elemento) | `Select` de PrimeVue sin valor inicial, con el texto «Seleccione» | Obliga a elegir | `Rule::enum(...)`, `in:...` o `exists:tabla,id` |

En Vue, la columna «Control en el front» se arma con un esquema zod por formulario, en un módulo
compartido, para no repetir la regla en cada vista.

Los mensajes son los de la tesis, al pie de la letra y tratando de usted. «Este dato es requerido»
vale para todo campo obligatorio vacío y no se repite en las tablas de abajo.

## 3. Tabla de campos

Fuente: la tabla de especificación de cada caso de uso (capítulo 1) y el diagrama entidad-relación
(Figura 7), que fija los campos de cada tabla. Obligatorio: **S** = sí, N = no. Las longitudes no
están en la tesis: son las de la columna que se propone. «Backend hoy» es lo que existe en el código
al 29/10/2026; se actualiza en el mismo commit que agrega el `FormRequest` de cada caso de uso.

### Usuarios (CU02)

Columnas de la lista: nombre, correo, rol y estado. Acciones: «Editar» y «Desactivar» (no se
eliminan; nadie puede desactivarse a sí mismo). Al guardar: «Usuario registrado correctamente» y se envía la
contraseña temporal al correo: el usuario nace con `debe_cambiar_password` y debe cambiarla al ingresar. Si el correo
no sale, el alta se revierte y se muestra «No se pudo enviar la contraseña temporal al correo…».

| Campo | Columna (DER) | Tipo | Oblig. | Longitud | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|---|
| Nombres | `USUARIO.nombres` | texto | S | 1–100 | — | — | `GuardarUsuarioRequest.php`: `required`, `max:100`. |
| Apellidos | `USUARIO.apellidos` | texto | S | 1–100 | — | — | `GuardarUsuarioRequest.php`: `required`, `max:100`. |
| Correo | `USUARIO.correo` | correo | S | ≤ 255 | correo con dominio, único | El correo ya está registrado | `GuardarUsuarioRequest.php`: `required`, `max:255`, `email:rfc` con punto en el dominio y `unique` (sin distinguir mayúsculas por la intercalación de MySQL), más el índice `UNIQUE`. |
| Rol | `USUARIO.rol_id` | lista | S | — | uno de `App\Enums\Rol` | — | `GuardarUsuarioRequest.php`: `Rule::enum` y que exista en `roles`; el `rol_id` se resuelve por la clave. Nadie cambia su propio rol, y siempre queda al menos un administrador activo (`UsuarioController.php`). |
| Proyectos asignados | `USUARIO_PROYECTO` | lista múltiple | S | al menos 1 | proyectos existentes | — | `GuardarUsuarioRequest.php`: `array`, `min:1`, cada id `integer`, `distinct` y `exists:proyectos,id`. `POST`/`PUT /usuarios` reemplazan las filas de `usuario_proyecto`. Las opciones salen de `GET /proyectos/opciones` (solo id y nombre). |

### Proyectos (CU03)

Columnas de la lista: código, nombre, dirección, fechas y estado. Al guardar: «Proyecto registrado
correctamente».

| Campo | Columna (DER) | Tipo | Oblig. | Longitud | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|---|
| Código | `PROYECTO.codigo` | texto | S | 1–20 | único | El código de proyecto ya existe | `GuardarProyectoRequest.php`: `required`, `max:20`, `unique` (sin distinguir mayúsculas por la intercalación de MySQL) e índice `UNIQUE`. |
| Nombre | `PROYECTO.nombre` | texto | S | 1–120 | — | — | `GuardarProyectoRequest.php`: `required`, `max:120`. |
| Dirección | `PROYECTO.direccion` | texto | S | 1–150 | — | — | `GuardarProyectoRequest.php`: `required`, `max:150`. |
| Fecha de inicio | `PROYECTO.fecha_inicio` | fecha | S | — | — | — | `GuardarProyectoRequest.php`: `required`, `date_format:Y-m-d`. |
| Fecha de fin estimada | `PROYECTO.fecha_fin` | fecha | S | — | posterior a la de inicio | La fecha de fin debe ser posterior a la de inicio | `GuardarProyectoRequest.php`: `required`, `date_format:Y-m-d`, `after:fecha_inicio`. |
| Ingeniero responsable | `PROYECTO.ingeniero_id` | lista | S | — | usuario activo con rol Ingeniero de producción; al editar se conserva el que ya tenía aunque haya quedado inactivo | — | `GuardarProyectoRequest.php`: `exists` con esa condición, y clave foránea a `users`. Las opciones salen de `GET /proyectos/ingenieros` (solo id y nombre; `?conservar=` solo agrega al responsable de un proyecto existente). |

`PROYECTO.estado` (Activo o Finalizado) se muestra en la lista y solo aparece en el formulario al
editar: un proyecto nuevo empieza Activo (la Figura 13 no lo trae en el registro). En el backend,
`POST` ignora el estado que llegue y `PUT` lo valida con `App\Enums\EstadoProyecto`.

Registran y actualizan solo Oficina técnica (actor de CU03); el administrador puede consultar la
lista sin modificarla, como en el resto de módulos. No hay ruta para eliminar proyectos.

### Partidas del presupuesto (CU04)

La partida pertenece al proyecto, no a un nivel (`PRESUPUESTO.proyecto_id`). Columnas de la lista:
elemento, f'c, metrado m³, % de desperdicio, precio unitario S/ y total S/, con el total
presupuestado al pie (Figura 14). Al guardar: «Partida registrada» y se actualiza el total.

| Campo | Columna (DER) | Tipo | Oblig. | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Elemento estructural | `PRESUPUESTO.elemento` | lista | S | — | — | No existe. |
| Resistencia f'c | `PRESUPUESTO.resistencia_id` | lista del catálogo `RESISTENCIA` (activas) | S | — | Este dato es requerido; La resistencia no existe | No existe. |
| Metrado (m³) | `PRESUPUESTO.metrado_m3` | número | S | > 0, dos decimales | Ingrese un valor mayor a cero | No existe. |
| Factor de desperdicio (%) | `PRESUPUESTO.factor_desperdicio` | número | S | ≥ 0, dos decimales; si pasa de 15 %, se pide confirmar el valor | Ingrese un valor mayor o igual a cero (no está en la tesis; el control no deja escribir negativos) | No existe. |
| Precio unitario (S/ por m³) | `PRESUPUESTO.precio_unitario` | número | S | > 0, dos decimales | Ingrese un valor mayor a cero | No existe. |

Cálculo: volumen con desperdicio = metrado × (1 + desperdicio ÷ 100); total de la partida = volumen
con desperdicio × precio unitario, redondeado a dos decimales con medio céntimo hacia arriba. El
cálculo se hace en céntimos enteros, no en coma flotante: 0,31 m³ × S/ 420,50 = S/ 130,36 (en coma
flotante da 130,354999… y se redondearía a 130,35). El total presupuestado es la suma de los
totales ya redondeados de las partidas, y el disponible de CU05 también se resta en céntimos. En el front, las dos fórmulas están solo en
`frontend/src/services/presupuesto.ts`.

Al editar una partida, el metrado no puede quedar por debajo del volumen ya repartido en sectores
de cualquier nivel (CU05): «El metrado es menor al volumen ya repartido en sectores (76.00 m³)».
Sin esta regla, el disponible del elemento quedaría negativo. No está en la tesis: se deduce de
CU05.

El selector de proyecto ofrece solo los proyectos asignados al usuario de la sesión
(`USUARIO_PROYECTO`), no todos. Tampoco está escrito en CU04: se deduce de la descripción de
`USUARIO_PROYECTO` en el DER («Proyectos a los que tiene acceso cada usuario») y de la maqueta.

Las opciones de elemento estructural son las de la maqueta y están en
`frontend/src/catalogos/partidas.ts` y `config/sicco.php`, hasta que se confirmen. Las de f'c salen
del catálogo `RESISTENCIA` (`GET /api/v1/resistencias`), que comparten el presupuesto y las guías.

### Niveles y sectores (CU05)

Una sola pantalla: a la izquierda los niveles del proyecto ordenados por `orden`, a la derecha los
sectores del nivel elegido con su elemento (`elemento · f'c`, leído de la partida), el volumen
presupuestado y el vaciado (solo lectura; lo suma la asignación de guías, CU09). Solo el Ingeniero
de producción entra a `/niveles`. Al guardar: «Registro creado.» para un nivel nuevo, «Sector
registrado» para un sector nuevo y «Cambios guardados.» al editar.

Si el proyecto no tiene partidas (precondición de CU04), se muestra «Este proyecto aún no tiene
presupuesto registrado. Oficina técnica debe registrar sus partidas antes de definir los
sectores.» y se bloquean «Nuevo nivel» y «Nuevo sector». Sin niveles, «Nuevo sector» avisa
«Primero registre un nivel con «Nuevo nivel».» (flujo alternativo 1).

| Campo | Columna (DER) | Tipo | Oblig. | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Nivel · nombre | `NIVEL.nombre` | texto (1–40) | S | — | Este dato es requerido | No existe. |
| Nivel · orden | `NIVEL.orden` | número entero | S | > 0 | Este dato es requerido; Ingrese un valor mayor a cero; Ingrese un número entero (el control no deja escribir decimales) | No existe. |
| Sector · nombre | `SECTOR.nombre` | texto (1–40) | S | — | Este dato es requerido | No existe. |
| Sector · elemento estructural | `SECTOR.presupuesto_id` | lista de partidas del proyecto | S | — | Este dato es requerido | No existe. |
| Sector · volumen presupuestado (m³) | `SECTOR.volumen_presup_m3` | número | S | > 0, dos decimales, no mayor que el disponible del elemento | Este dato es requerido; Ingrese un valor mayor a cero; El volumen excede lo presupuestado para el elemento | No existe. |

Disponible del elemento = metrado de la partida − volumen presupuestado de los demás sectores
enlazados a esa partida, en todos los niveles del proyecto; al editar un sector no se descuenta el
suyo. Se redondea a dos decimales y no aplica el desperdicio, que CU14 aplica después sobre el
volumen del sector. La tesis solo dice «presupuesto disponible del elemento»: la fórmula es la de
la maqueta y está solo en `frontend/src/services/presupuesto.ts` (`volumenDisponible`). El
formulario muestra el disponible de cada partida: «Disponible por elemento: Zapata · f'c 210
kg/cm², 244.00 m³; …».

No están en la tesis y se infirieron: el largo máximo de 40 de los dos nombres (de la maqueta), que
no se pueda eliminar niveles ni sectores (el flujo solo describe el registro, igual que en CU04),
que los nombres no tengan que ser únicos (la tesis no lo pide; dos niveles con el mismo nombre y
orden se listan por orden de registro) y que el selector ofrezca solo los proyectos activos
asignados al usuario de la sesión (igual que en CU04).

El sector no lleva fecha de vaciado ni estado: el vaciado se registra en la asignación de guías
(`SECTOR.volumen_vaciado_m3` y `ASIGNACION`). Un sector nuevo empieza con 0 m³ vaciados.

### Guías de remisión (CU06–CU08)

Pantalla `/guias/registrar`, solo para el Almacenero y pensada para el celular; el proyecto es el
del selector del encabezado. Paso 1: tomar la foto (se reduce a 1600 px en el navegador antes de
subirla). Paso 2: «Leer guía» (`POST /guias/lecturas`, CU07) llena el formulario con lo leído, o
«Registrar manualmente» (CU08) lo abre vacío con la fecha de hoy. Si el OCR no responde en 20 s,
falla o la imagen no es una guía, se muestra «No se pudo leer la guía» y sigue el registro manual
con la foto ya adjunta. Al guardar: «Guía registrada correctamente» y la pantalla vuelve al paso 1.

La confianza de cada dato leído es la que informa el modelo, rebajada a 0.4 si el valor no pasa una
verificación propia (dígito verificador del RUC, número con serie `XXXX-NNNNNNNN`, fecha válida de
hasta un año atrás, volumen de hasta 12 m³, placa `ABC-123`). Bajo 0.8 (`OCR_UMBRAL_CONFIANZA`) el
campo muestra en ámbar «Verifique este dato» hasta que se corrige; lo no identificado queda vacío
con «No se pudo leer; ingrese este dato» (CU07 flujo alternativo 2).

| Campo | Columna (DER) | Tipo | Oblig. | Regla | Mensaje |
|---|---|---|---|---|---|
| Foto | `GUIA_REMISION.foto_ruta` | JPG, PNG o WebP | S | ≤ 10 MB | Adjunte una foto JPG, PNG o WebP de hasta 10 MB |
| Número de guía | `numero` | texto (1–20), en mayúsculas y sin espacios | S | único por proveedor | Este dato es requerido; La guía ya fue registrada |
| Fecha | `fecha` | fecha | S | no posterior a hoy | Este dato es requerido; La fecha no puede ser posterior a hoy |
| Proveedor | `proveedor_id` | lista de proveedores, o el RUC nuevo leído | S | RUC de 11 dígitos | Este dato es requerido |
| Resistencia | `resistencia_id` | lista del catálogo `RESISTENCIA` (activas) | S | igual a la del producto, si su código ya está registrado | Este dato es requerido; El producto 70280NB5746 está registrado como f'c 280 kg/cm² |
| Código del producto | `producto_id` → `PRODUCTO_PROVEEDOR.codigo` | texto (0–30), en mayúsculas y sin espacios | N | — | — |
| Volumen (m³) | `volumen_m3` | número | S | > 0, dos decimales, ≤ 99.99 | Este dato es requerido; Ingrese un valor mayor a cero; El valor es demasiado grande |
| Placa del mixer | `placa` | texto (0–10), en mayúsculas | N | — | — |

No están en la tesis y se infirieron: que el número se repita solo dentro del mismo proveedor (cada
concretera numera sus guías), que un RUC leído con seguridad y no registrado se ofrezca como
proveedor nuevo y se registre con la guía (arquitectura §6.9), el tope de 99.99 m³ y que la foto
leída y no registrada se borre al día siguiente (tarea programada).

**Resistencia de la guía.** La tesis pide la resistencia como dato de la guía; se guarda como
referencia al catálogo `RESISTENCIA` (el mismo del presupuesto) para poder compararla con la del
sector al asignar (CU09) y agrupar en los reportes. La lectura la resuelve en este orden:

1. El producto de la concretera (`PRODUCTO_PROVEEDOR`) con el código leído: confianza 1. Manda
   sobre lo que haya leído el modelo.
2. El patrón de código de la concretera (`PROVEEDOR.patron_codigo`, p. ej. `^70(?<fc>\d{3})` en
   Holcim: 70280NB5746 es f'c 280): confianza 0.9.
3. El f'c que leyó el modelo, si está en el catálogo. Si no está, queda vacía y por revisar.

`PROVEEDOR.indicaciones_ocr` (dónde indica cada concretera la resistencia) se agrega al prompt del
OCR. Al registrar una guía con un código de producto nuevo, el producto se registra con la
resistencia que eligió el almacenero, y las guías siguientes con ese código se resuelven solas.
`ConcreteraSeeder` carga Holcim y Arquitectura Construida con sus productos, tomados de las guías y
facturas del proyecto Larco. Nada de esto está en la tesis.

### Guías sin sector (CU10)

Pantalla `/guias/sin-sector`, para Producción y Oficina técnica (el administrador consulta); el
proyecto es el del selector del encabezado. Al entrar lista todas las guías con volumen por asignar
(`sin_sector` y `parcial`), de la más antigua a la más reciente; «Buscar» las acota por fechas. Las
de más de 2 días se resaltan en rojo. Sin resultados: «No hay guías sin sector asignado».
«Exportar a Excel» descarga la misma lista (`GET …/guias/sin-sector/exportar`, con OpenSpout).

| Campo | Tipo | Oblig. | Regla | Mensaje |
|---|---|---|---|---|
| Desde | fecha | N | — | Ingrese una fecha válida |
| Hasta | fecha | N | igual o posterior a «Desde» | La fecha final debe ser igual o posterior a la inicial |

No están en la tesis y se infirieron: que los días se cuenten desde la fecha de la guía (el día
que llegó el concreto) y no desde su registro, que se listen también las guías asignadas en parte
(con su volumen pendiente) y que la lista se cargue al entrar, sin esperar a «Buscar». Los días se
calculan con la zona horaria de la aplicación (UTC), igual que la regla «fecha no posterior a hoy».

### Asignación de guías (CU09)

Pantalla `/guias/:id/asignar`, abierta con «Asignar» desde «Guías sin sector»; solo la guarda el
Ingeniero de producción. Muestra los datos de la guía y el volumen disponible, y una fila por sector
con los sectores del proyecto agrupados por nivel. «Agregar sector» propone lo que falta para
completar la guía. Al guardar: «Guía asignada correctamente» y vuelve a la lista. Sin sectores en el
proyecto, avisa que primero se registren en «Niveles y sectores» (flujo alternativo 3).

| Campo | Columna (DER) | Tipo | Oblig. | Regla | Mensaje |
|---|---|---|---|---|---|
| Sector | `ASIGNACION.sector_id` | lista de sectores del proyecto de la guía | S | sin repetir; del mismo proyecto | Este dato es requerido; El sector ya está en la asignación; El sector no pertenece al proyecto de la guía |
| Volumen vaciado (m³) | `ASIGNACION.volumen_m3` | número | S | > 0, dos decimales, ≤ 99.99 | Este dato es requerido; Ingrese un valor mayor a cero; Ingrese como máximo dos decimales |
| (todas las filas) | — | — | S | al menos una; la suma no supera el volumen de la guía | Este dato es requerido; El volumen asignado supera el de la guía |

Guardar reemplaza el reparto completo de la guía, así el mismo formulario corrige una asignación:
el volumen vaciado de cada sector se ajusta por la diferencia, la guía queda `asignada` si la suma
iguala su volumen y `parcial` si no, y cada guardado deja el reparto anterior y el nuevo en
`historial_asignaciones` (requerimiento especial de CU09; tabla propia en lugar de
`spatie/laravel-activitylog`). Si el f'c de la partida del sector no es el de la guía, la pantalla
lo avisa sin impedirlo. Si un sector pasa lo presupuestado × (1 + desperdicio), la respuesta lo
indica y la pantalla lo avisa; registrar y enviar la alerta queda para CU14.

## 4. Diferencias entre el front y el backend

1. **Solo la autenticación tiene reglas en el servidor:** `LoginRequest` y `CambiarPasswordRequest`.
   La contraseña nueva sigue `Password::defaults()` = mínimo 8 caracteres, letras y números
   (`AppServiceProvider.php:30`).
2. **Cada caso de uso trae su `FormRequest` en su propio commit.** Usuarios, proyectos, partidas,
   niveles y sectores todavía no tienen endpoint. Su `FormRequest`, con las reglas de la tabla de la
   sección 3 y una prueba por regla, se agrega en el commit de ese caso de uso, junto con el
   endpoint, y no antes: una regla sin endpoint no se puede probar de punta a punta.
3. **Largo máximo en la autenticación:**

   | Formulario | Campo | Regla | Por qué |
   |---|---|---|---|
   | Iniciar sesión | `email` | `max:255` | Largo de la columna `users.email`; evita procesar entradas enormes. |
   | Iniciar sesión | `password` | `max:255` | Evita procesar entradas enormes antes de comparar el hash. |
   | Cambiar contraseña | `password_actual` | `max:255` | Ídem. |
   | Cambiar contraseña | `password` (nueva) | `max:72` | bcrypt usa solo los primeros 72 bytes: con más, dos contraseñas que comparten esos 72 bytes valdrían igual. |

4. **Nombres de columnas:** el DER usa `correo`, `estado` y `password_hash`; la tabla `users` usa
   `email`, `activo` y `password` (`User.php:15`). Los demás campos del usuario coinciden.
5. **Largo solo en la base:** `nombres` y `apellidos` tienen `varchar(100)`, pero todavía no hay regla
   `max:100`. Un texto más largo daría error 500 en vez de 422. Se corrige con el `FormRequest` de
   usuarios (punto 2).
6. **Reglas duplicadas a mano:** `CambiarPasswordView.vue:26-28` copia la regla de
   `Password::defaults()`. Si una cambia, la otra no se entera. Por eso cada regla se documenta aquí
   y se prueba en los dos lados con los mismos casos.
7. **«Distinta de la actual»** (`different:password_actual`) solo la valida el backend. Es correcto:
   el front no conoce la contraseña actual.
8. **Dos decimales solo en el control:** el `InputNumber` no deja escribir más de dos decimales, pero
   los esquemas zod de partida y sector aceptan 1,005, 1e15 e `Infinity`. El `FormRequest` de cada
   caso de uso tiene que rechazarlos con `decimal:0,2` y un tope (`max:`) según la precisión de la
   columna, que el DER todavía no fija.

## 5. Pendiente de definir

- Que la tesis confirme los valores del estado del proyecto (hoy Activo o Finalizado, lo cambia
  Oficina técnica al editar) y que el ingeniero responsable sea un usuario activo con rol
  «Ingeniero de producción», como hace hoy el front.
- La lista de elementos estructurales y los valores iniciales del catálogo de resistencias f'c
  (`ResistenciaSeeder`: 175, 210, 280 y 350).
- Quién mantiene los catálogos de resistencias y de productos de las concreteras: hoy solo se
  cargan con seeders y los productos nuevos se registran con las guías; no hay pantalla.
- Que la tesis confirme la fórmula del total de la partida, que hoy se infirió (ver CU04).
