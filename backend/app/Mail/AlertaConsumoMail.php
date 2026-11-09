<?php

namespace App\Mail;

use App\Models\Alerta;
use App\Services\Desperdicio\CalculadoraDesperdicio;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** CU14 paso 3: aviso de que un sector pasó lo presupuestado más su desperdicio. */
class AlertaConsumoMail extends Mailable
{
    public function __construct(public Alerta $alerta) {}

    public function envelope(): Envelope
    {
        $sector = $this->alerta->sector;

        return new Envelope(
            subject: "Alerta de consumo: {$sector->nivel->nombre} – {$sector->nombre} ({$this->alerta->proyecto->codigo})",
        );
    }

    public function content(): Content
    {
        $sector = $this->alerta->sector;

        return new Content(view: 'mail.alerta-consumo', with: [
            'proyecto' => $this->alerta->proyecto,
            'sector' => $sector,
            'elemento' => $sector->partida->elemento.' · '.$sector->partida->resistencia->nombre,
            'limite' => CalculadoraDesperdicio::limiteCentesimos($sector) / 100,
            'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/alertas',
        ]);
    }
}
