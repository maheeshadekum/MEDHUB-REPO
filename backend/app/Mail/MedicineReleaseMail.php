<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MedicineReleaseMail extends Mailable
{
    use Queueable, SerializesModels;

    public $prescription;
    public $patient;
    public $hospital;

    /**
     * Create a new message instance.
     */
    public function __construct($prescription, $patient, $hospital)
    {
        $this->prescription = $prescription;
        $this->patient = $patient;
        $this->hospital = $hospital;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Medicine Release Confirmation - ' . $this->hospital->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.medicine-release',
            with: [
                'prescription' => $this->prescription,
                'patient' => $this->patient,
                'hospital' => $this->hospital,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
