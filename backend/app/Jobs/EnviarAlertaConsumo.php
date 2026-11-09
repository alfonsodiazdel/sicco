<?php

namespace App\Jobs;

use App\Enums\Rol;
use App\Mail\AlertaConsumoMail;
use App\Models\Alerta;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * CU14 paso 3: envía la alerta por correo al ingeniero responsable del proyecto y a gerencia.
 *
 * Flujo alternativo 1: si el correo no sale, la cola lo reintenta con espera creciente; la alerta
 * ya está visible en el sistema con el correo pendiente. Si se agotan los intentos, el comando
 * programado `alertas:reenviar-correos` lo vuelve a encolar.
 */
class EnviarAlertaConsumo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> segundos entre intentos */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $alertaId) {}

    /** Una sola copia en cola por alerta: el reenvío programado no la duplica. */
    public function uniqueId(): string
    {
        return (string) $this->alertaId;
    }

    public function handle(): void
    {
        $alerta = Alerta::with(['proyecto.ingeniero', 'sector.nivel', 'sector.partida.resistencia'])
            ->find($this->alertaId);
        if (! $alerta || $alerta->correo_enviado_at) {
            return;
        }

        $destinatarios = self::destinatarios($alerta);
        if ($destinatarios->isEmpty()) {
            Log::warning('Alerta de consumo sin destinatarios activos', ['alerta_id' => $alerta->id]);

            return;
        }

        // Un solo mensaje: si falla, el reintento no duplica el correo a quien ya lo recibió.
        Mail::to($destinatarios->all())->send(new AlertaConsumoMail($alerta));

        $alerta->forceFill(['correo_enviado_at' => now()])->save();
    }

    public function failed(?Throwable $error): void
    {
        Log::error('No se pudo enviar la alerta de consumo', [
            'alerta_id' => $this->alertaId,
            'error' => $error?->getMessage(),
        ]);
    }

    /** @return Collection<int, User> ingeniero responsable y gerencia del proyecto, activos */
    public static function destinatarios(Alerta $alerta): Collection
    {
        $gerencia = $alerta->proyecto->usuarios()
            ->where('activo', true)
            ->whereHas('rol', fn ($q) => $q->where('clave', Rol::Gerencia->value))
            ->get();

        return collect([$alerta->proyecto->ingeniero])
            ->filter(fn (?User $u) => $u !== null && $u->activo)
            ->merge($gerencia)
            ->unique('email')
            ->values();
    }
}
