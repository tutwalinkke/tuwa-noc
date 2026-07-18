<?php

namespace App\Mail;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeviceDownAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Device $device,
        public string $eventMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Device Down: {$this->device->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.device-down-alert',
        );
    }
}
