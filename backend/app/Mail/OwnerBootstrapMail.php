<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OwnerBootstrapMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $companyName,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Create the installation Owner for '.$this->companyName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.owner-bootstrap', with: [
            'companyName' => $this->companyName,
            'acceptUrl' => route('installation.owner.show', ['token' => $this->token]),
        ]);
    }
}
