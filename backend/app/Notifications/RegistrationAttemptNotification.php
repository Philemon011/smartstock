<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationAttemptNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tentative d'inscription avec votre adresse email")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Quelqu'un vient d'essayer de créer un espace avec votre adresse email, alors qu'un compte existe déjà.")
            ->line("Si c'était vous, connectez-vous ou réinitialisez votre mot de passe. Pour rejoindre une entreprise existante, demandez à son propriétaire de vous inviter.")
            ->line("Si ce n'était pas vous, ignorez ce message : votre compte n'a pas été modifié.")
            ->salutation("L'équipe ".config('app.name'));
    }
}