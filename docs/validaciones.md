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
| Cantidad (m³, %) | `type="number"` con `min`, `max` y `step`; bloquea `e`, `+` y `-` | Teclado numérico y rango nativo | `numeric\|between:min,max` + `decimal:0,1` |
| DNI, celular | `type="text"`, `inputmode="numeric"`, `pattern`, `maxlength`; solo entran dígitos (al pegar, se quitan los demás caracteres) | Pueden empezar con 0, y `type=number` borraría ese 0 | `string\|regex:/^\d{8}$/` |
| Texto libre | `maxlength` igual al de la columna | Evita errores de la base | `string\|max:n` |
| Nombres de persona | `pattern` de letras, espacios y apóstrofo | Lista de lo permitido | `regex:/^[\pL' ]+$/u` |
| Correo | `type="email"` + comprobación de dominio con punto | El navegador acepta `x@y` | `email:rfc\|max:255`, guardado en minúsculas |
| Fecha | `type="date"` con `min` y `max` | Calendario nativo | `date\|after_or_equal:...` |
| Lista (rol, estado) | `<select>` con opción vacía «Selecciona…» | Obliga a elegir | `Rule::enum(...)` o `in:...` |

En Vue, la columna «Control en el front» se arma con un esquema zod por formulario, en un módulo
compartido, para no repetir la regla en cada vista.

## 3. Tabla de campos

Obligatorio: **S** = sí, N = no. «Backend hoy» es lo que existe en el código al 29/10/2026; se
actualiza en el mismo commit que agrega el `FormRequest` de cada caso de uso.

### Usuarios (CU02)

| Campo | Tipo | Oblig. | Longitud | Formato | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Nombres | texto | S | 1–100 | letras, espacios y ' | Este campo es obligatorio. / Usa solo letras y espacios. | Solo la columna `varchar(100) NOT NULL` (migración `..._000000_create_users_table.php`). Sin regla en ningún `FormRequest`. |
| Apellidos | texto | S | 1–100 | ídem | ídem | ídem |
| DNI | dígitos | S | 8 | `^\d{8}$`, único | El DNI debe tener 8 dígitos. / Ya existe un usuario con ese DNI. | **La columna no existe** (tampoco en `User.php:15`). |
| Celular | dígitos | N | 9 | `^9\d{8}$` | El celular debe tener 9 dígitos y empezar con 9. | **La columna no existe.** |
| Correo | correo | S | ≤ 255 | correo con dominio, único | Ingresa un correo válido, por ejemplo nombre@empresa.com. / Ya existe un usuario con ese correo. | Solo el índice `UNIQUE` en la base. `LoginRequest.php:19` valida `email` solo al iniciar sesión. |
| Rol | lista | S | — | uno de `App\Enums\Rol` | Este campo es obligatorio. | El enum existe (`Rol.php:11-15`), pero ninguna regla lo usa. |

### Proyectos (CU03)

| Campo | Tipo | Oblig. | Longitud | Formato | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Nombre | texto | S | 1–120 | único | Ya existe un proyecto con ese nombre. | No existe (sin tabla, modelo ni ruta). |
| Ubicación | texto | S | 1–150 | — | Este campo es obligatorio. | No existe. |
| Inicio | fecha | S | — | 2020-01-01 a 2035-12-31 | Este campo es obligatorio. | No existe. |
| Fin estimado | fecha | N | — | ≥ inicio | La fecha de fin no puede ser anterior a la de inicio. | No existe. |

### Partidas del presupuesto (CU04)

| Campo | Tipo | Oblig. | Longitud | Formato | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Nivel | lista | S | — | nivel del mismo proyecto | Este campo es obligatorio. | No existe. |
| Concreto | lista | S | — | f'c 175/210/280/350 kg/cm² | Este campo es obligatorio. | No existe. |
| Elemento | texto | S | 1–80 | — | Este campo es obligatorio. | No existe. |
| Volumen (m³) | número | S | — | 0.1–10000, paso 0.1 | Ingresa un valor entre 0.1 y 10000. | No existe. |
| Desperdicio (%) | número | S | — | 0–20, paso 0.5 | Ingresa un valor entre 0 y 20. | No existe. |

### Niveles y sectores (CU05)

| Campo | Tipo | Oblig. | Longitud | Formato | Mensaje | Backend hoy |
|---|---|---|---|---|---|---|
| Nivel · nombre | texto | S | 1–40 | único en el proyecto | Ese nivel ya existe en el proyecto. | No existe. |
| Sector · código | texto | S | 2–4 | `^S\d{1,3}$`, único en el nivel | Usa la letra S seguida de un número, por ejemplo S4. / Ese código ya existe en el nivel. | No existe. |
| Sector · elemento | texto | S | 1–80 | — | Este campo es obligatorio. | No existe. |
| Sector · volumen teórico | número | S | — | 0.1–2000, paso 0.1 | Ingresa un valor entre 0.1 y 2000. | No existe. |
| Sector · fecha de vaciado | fecha | S | — | 2020-01-01 a 2035-12-31 | Este campo es obligatorio. | No existe. |
| Sector · estado | lista | S | — | Programado / Vaciado | Este campo es obligatorio. | No existe. |

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

4. **DNI y celular** están en el diseño, pero no en la tabla `users`. Es una decisión pendiente: si
   van, hace falta una migración (`dni char(8) unique`, `celular char(9) null`); si no van, se
   quitan del formulario. Hasta esa decisión no se crea la migración.
5. **Largo solo en la base:** `nombres` y `apellidos` tienen `varchar(100)`, pero todavía no hay regla
   `max:100`. Un texto más largo daría error 500 en vez de 422. Se corrige con el `FormRequest` de
   usuarios (punto 2).
6. **Reglas duplicadas a mano:** `CambiarPasswordView.vue:26-28` copia la regla de
   `Password::defaults()`. Si una cambia, la otra no se entera. Por eso cada regla se documenta aquí
   y se prueba en los dos lados con los mismos casos.
7. **«Distinta de la actual»** (`different:password_actual`) solo la valida el backend. Es correcto:
   el front no conoce la contraseña actual.

## 5. Preguntas abiertas

- ¿El usuario lleva DNI y celular? (punto 4.4)
- ¿Los rangos de volumen (hasta 10 000 m³ por partida y 2 000 m³ por sector) y de desperdicio
  (0–20 %) son realistas para las obras?
