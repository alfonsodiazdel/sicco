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
| Cantidad (m³, S/, %) | `type="number"` con `min` y `step="0.01"`; bloquea `e`, `+` y `-` | Teclado numérico; la tesis pide dos decimales | `numeric\|gt:0` (o `min:0` en el desperdicio) + `decimal:0,2` |
| Texto libre | `maxlength` igual al de la columna | Evita errores de la base | `string\|max:n` |
| Correo | `type="email"` + comprobación de dominio con punto | El navegador acepta `x@y` | `email:rfc\|max:255`, guardado en minúsculas |
| Fecha | `type="date"`; el fin, con `min` = inicio + 1 día | Calendario nativo | `date`; el fin, `after:fecha_inicio` |
| Lista (rol, ingeniero, elemento) | `<select>` con opción vacía «Seleccione…» | Obliga a elegir | `Rule::enum(...)`, `in:...` o `exists:tabla,id` |

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
eliminan). Al guardar: «Usuario registrado correctamente» y se envía la contraseña temporal al correo.

| Campo | Columna (DER) | Tipo | Oblig. | Longitud | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|---|
| Nombres | `USUARIO.nombres` | texto | S | 1–100 | — | — | Solo la columna `varchar(100) NOT NULL` (migración `..._000000_create_users_table.php`). Sin regla en ningún `FormRequest`. |
| Apellidos | `USUARIO.apellidos` | texto | S | 1–100 | — | — | ídem |
| Correo | `USUARIO.correo` | correo | S | ≤ 255 | correo con dominio, único | El correo ya está registrado | Solo el índice `UNIQUE` en la base. `LoginRequest.php:19` valida `email` solo al iniciar sesión. |
| Rol | `USUARIO.rol_id` | lista | S | — | uno de `App\Enums\Rol` | — | El enum existe (`Rol.php:11-15`), pero ninguna regla lo usa. |
| Proyectos asignados | `USUARIO_PROYECTO` | lista múltiple | S | al menos 1 | proyectos existentes | — | No existe (sin tabla de proyectos). |

### Proyectos (CU03)

Columnas de la lista: código, nombre, dirección, fechas y estado. Al guardar: «Proyecto registrado
correctamente».

| Campo | Columna (DER) | Tipo | Oblig. | Longitud | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|---|
| Código | `PROYECTO.codigo` | texto | S | 1–20 | único | El código de proyecto ya existe | No existe (sin tabla, modelo ni ruta). |
| Nombre | `PROYECTO.nombre` | texto | S | 1–120 | — | — | No existe. |
| Dirección | `PROYECTO.direccion` | texto | S | 1–150 | — | — | No existe. |
| Fecha de inicio | `PROYECTO.fecha_inicio` | fecha | S | — | — | — | No existe. |
| Fecha de fin estimada | `PROYECTO.fecha_fin` | fecha | S | — | posterior a la de inicio | La fecha de fin debe ser posterior a la de inicio | No existe. |
| Ingeniero responsable | `PROYECTO.ingeniero_id` | lista | S | — | usuario existente | — | No existe. |

`PROYECTO.estado` no está en el formulario (Figura 13): se muestra en la lista.

### Partidas del presupuesto (CU04)

La partida pertenece al proyecto, no a un nivel (`PRESUPUESTO.proyecto_id`). Columnas de la lista:
elemento, f'c, metrado m³, % de desperdicio, precio unitario S/ y total S/, con el total
presupuestado al pie (Figura 14). Al guardar: «Partida registrada» y se actualiza el total.

| Campo | Columna (DER) | Tipo | Oblig. | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Elemento estructural | `PRESUPUESTO.elemento` | lista | S | — | — | No existe. |
| Resistencia f'c | `PRESUPUESTO.resistencia_fc` | lista | S | — | — | No existe. |
| Metrado (m³) | `PRESUPUESTO.metrado_m3` | número | S | > 0, dos decimales | Ingrese un valor mayor a cero | No existe. |
| Factor de desperdicio (%) | `PRESUPUESTO.factor_desperdicio` | número | S | ≥ 0, dos decimales; si pasa de 15 %, se pide confirmar el valor | — | No existe. |
| Precio unitario (S/ por m³) | `PRESUPUESTO.precio_unitario` | número | S | > 0, dos decimales | Ingrese un valor mayor a cero | No existe. |

Cálculo: volumen con desperdicio = metrado × (1 + desperdicio ÷ 100); total de la partida = volumen
con desperdicio × precio unitario.

### Niveles y sectores (CU05)

Para registrar sectores, el proyecto ya tiene presupuesto. Si no hay niveles, se registra primero
uno con «Nuevo nivel». Al guardar un sector: «Sector registrado».

| Campo | Columna (DER) | Tipo | Oblig. | Regla | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Nivel · nombre | `NIVEL.nombre` | texto (1–40) | S | — | — | No existe. |
| Nivel · orden | `NIVEL.orden` | número entero | S | > 0 | — | No existe. |
| Sector · nombre | `SECTOR.nombre` | texto (1–40) | S | — | — | No existe. |
| Sector · elemento estructural | `SECTOR.presupuesto_id` | lista de partidas del proyecto | S | — | — | No existe. |
| Sector · volumen presupuestado (m³) | `SECTOR.volumen_presup_m3` | número | S | > 0, dos decimales, no mayor que el metrado de la partida menos lo ya asignado a otros sectores | El volumen excede lo presupuestado para el elemento | No existe. |

El sector no lleva fecha de vaciado ni estado: el vaciado se registra en la asignación de guías
(`SECTOR.volumen_vaciado_m3` y `ASIGNACION`).

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

## 5. Pendiente de definir

- Los valores del estado del proyecto y quién lo cambia (el del usuario es activo o inactivo).
- Si el ingeniero responsable se elige solo entre los usuarios con rol «Ingeniero de producción».
- Las listas de elementos estructurales y de resistencias f'c.
- Si el total de la partida se calcula sobre el volumen con desperdicio o sobre el metrado.
