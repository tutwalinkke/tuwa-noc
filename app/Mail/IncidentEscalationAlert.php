<?php

namespace App\Mail;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IncidentEscalationAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public int $minutesOpen,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "ESCALATED: Unacknowledged incident on {$this->incident->device->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.incident-escalation-alert',
        );
    }
}
