<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Purpose-limited staff invitation. The token is never audited or stored in plaintext. */
final class StaffInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $companyName,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Staff invitation for '.$this->companyName);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.staff-invitation', with: [
            'companyName' => $this->companyName,
            'acceptUrl' => route('staff.invitation.show', ['token' => $this->token]),
        ]);
    }
}
