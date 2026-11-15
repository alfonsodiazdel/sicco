/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Site key pública de Cloudflare Turnstile. Vacía: el login no muestra captcha. */
  readonly VITE_TURNSTILE_SITE_KEY?: string
}

// API global de Cloudflare Turnstile (https://challenges.cloudflare.com/turnstile/v0/api.js).
interface Window {
  turnstile?: {
    render(
      contenedor: HTMLElement | string,
      opciones: {
        sitekey: string
        action?: string
        language?: string
        callback?: (token: string) => void
        'expired-callback'?: () => void
        'error-callback'?: () => void
      },
    ): string
    reset(widgetId?: string): void
    remove(widgetId?: string): void
  }
}
