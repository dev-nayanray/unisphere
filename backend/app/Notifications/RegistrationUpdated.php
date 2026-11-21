<?php

namespace App\Notifications;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells a student their course registration changed (approved, rejected, promoted from waitlist). */
class RegistrationUpdated extends Notification
{
    use Queueable;

    public function __construct(public Registration $registration)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->registration->loadMissing('section.course:id,code');
        $code = $this->registration->section->course->code;

        $title = match ($this->registration->status) {
            Registration::APPROVED => "{$code} registration approved",
            Registration::REJECTED => "{$code} registration rejected",
            Registration::PENDING => "{$code}: a seat opened up, waiting for approval",
            default => "{$code} registration updated",
        };

        return [
            'type' => 'registration',
            'title' => $title,
            'note' => $this->registration->note,
            'registration_id' => $this->registration->id,
        ];
    }
}
