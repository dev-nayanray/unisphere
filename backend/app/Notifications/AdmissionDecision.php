<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emailed to the applicant when their application is accepted or rejected. */
class AdmissionDecision extends Notification
{
    use Queueable;

    public function __construct(
        public Application $application,
        public ?string $studentId = null,
        public ?string $temporaryPassword = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $program = $this->application->program->name;

        if ($this->application->status === 'accepted') {
            return (new MailMessage)
                ->subject('Admission accepted - welcome to '.config('app.name'))
                ->greeting("Congratulations, {$this->application->name}!")
                ->line("You have been admitted to {$program}.")
                ->line("Student ID: {$this->studentId}")
                ->line("Login email: {$this->application->email}")
                ->line("Temporary password: {$this->temporaryPassword}")
                ->line('You will be asked to choose your own password when you first sign in.')
                ->action('Sign in to UniSphere', config('unisphere.frontend_url').'/login');
        }

        $mail = (new MailMessage)
            ->subject('Your admission application')
            ->greeting("Dear {$this->application->name},")
            ->line("Thank you for applying to {$program}. We are unable to offer you admission this time.");

        if ($this->application->review_note) {
            $mail->line('Note from the admissions office: '.$this->application->review_note);
        }

        return $mail;
    }
}
