// Debe coincidir con backend/app/Enums/Rol.php
export type RolClave =
  'administrador' | 'almacenero' | 'ingeniero_produccion' | 'oficina_tecnica' | 'gerencia'

export interface Rol {
  id: number
  clave: RolClave
  nombre: string
  descripcion: string | null
}

export interface Usuario {
  id: number
  nombres: string
  apellidos: string
  nombre_completo: string
  email: string
  activo: boolean
  debe_cambiar_password: boolean
  ultimo_acceso_at: string | null
  rol: Rol
}

export interface Credenciales {
  email: string
  password: string
  /** Token de Cloudflare Turnstile; solo si el captcha está activo. */
  captcha?: string
}

export interface CambioPassword {
  password_actual: string
  password: string
  password_confirmation: string
}
