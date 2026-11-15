<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifica el token de Cloudflare Turnstile en el servidor (siteverify).
 *
 * Cada token sirve una sola vez. Se rechaza si Cloudflare no lo aprueba, si la acción no es
 * la del formulario o si el hostname no está en TURNSTILE_HOSTNAMES.
 */
class Captcha implements ValidationRule
{
    private const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly string $accion) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('services.turnstile.enabled')) {
            return;
        }

        $secret = (string) config('services.turnstile.secret');

        if ($secret === '' || ! is_string($value) || $value === '') {
            $fail(__('sicco.auth.captcha'));

            return;
        }

        try {
            $respuesta = Http::asForm()
                ->timeout(config('services.turnstile.timeout'))
                ->post(self::SITEVERIFY, [
                    'secret' => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ])
                ->json();
        } catch (ConnectionException $e) {
            Log::warning('Turnstile: siteverify no respondió', ['error' => $e->getMessage()]);
            $fail(__('sicco.auth.captcha'));

            return;
        }

        if (! $this->esValida(is_array($respuesta) ? $respuesta : [], $secret)) {
            $fail(__('sicco.auth.captcha'));
        }
    }

    private function esValida(array $respuesta, string $secret): bool
    {
        if (($respuesta['success'] ?? false) !== true) {
            return false;
        }

        // Las claves de prueba de Cloudflare no devuelven la acción ni el hostname reales.
        if ($this->esSecretDePrueba($secret)) {
            return true;
        }

        if (($respuesta['action'] ?? null) !== $this->accion) {
            return false;
        }

        $hostnames = config('services.turnstile.hostnames');

        return $hostnames === [] || in_array($respuesta['hostname'] ?? null, $hostnames, true);
    }

    private function esSecretDePrueba(string $secret): bool
    {
        return (bool) preg_match('/^[123]x0{20,}/', $secret);
    }
}
