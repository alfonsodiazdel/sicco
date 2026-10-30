<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Contraseña temporal del usuario nuevo. Se envía sin cola: el alta se revierte si el
 * envío falla, y así la contraseña tampoco queda guardada en la tabla de trabajos.
 */
class PasswordTemporalMail extends Mailable
{
    public function __construct(public User $usuario, public string $password) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Su acceso a SICCO');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-temporal', with: [
            'url' => config('app.frontend_url', config('app.url')),
        ]);
    }
}
