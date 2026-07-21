<?php

namespace App\Mail;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BandwidthThresholdAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Device $device,
        public string $direction,
        public int $currentBps,
        public int $thresholdBps,
    ) {}

    public function envelope(): Envelope
    {
        $directionLabel = ucfirst($this->direction);
        return new Envelope(
            subject: "Bandwidth Threshold Exceeded: {$this->device->name} ({$directionLabel})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.bandwidth-threshold-alert',
        );
    }
}
