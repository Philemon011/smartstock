<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
        public function register(): void
    {
        $this->app->scoped(\App\Support\Tenancy\CurrentTenant::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureVerificationEmail();
        $this->configureResetPasswordEmail();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perHour(10)->by('register-ip|'.$request->ip()),
            Limit::perHour(3)->by('register-email|'.$this->emailKey($request)),
        ]);

        RateLimiter::for('resend-verification', fn (Request $request): array => [
            Limit::perMinute(1)->by('resend-pair|'.$request->ip().'|'.$this->emailKey($request)),
            Limit::perHour(5)->by('resend-email|'.$this->emailKey($request)),
            Limit::perHour(20)->by('resend-ip|'.$request->ip()),
        ]);

        RateLimiter::for('verify-email', fn (Request $request): Limit => Limit::perMinute(20)->by('verify-ip|'.$request->ip()));

        
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(30)->by('login-ip|'.$request->ip()));

        RateLimiter::for('forgot-password', fn (Request $request): array => [
            Limit::perMinute(1)->by('forgot-pair|'.$request->ip().'|'.$this->emailKey($request)),
            Limit::perHour(5)->by('forgot-email|'.$this->emailKey($request)),
            Limit::perHour(20)->by('forgot-ip|'.$request->ip()),
        ]);

        RateLimiter::for('reset-password', fn (Request $request): Limit => Limit::perMinute(10)->by('reset-ip|'.$request->ip()));
    }

    private function configureVerificationEmail(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $appName = config('app.name');
            $trialDays = (int) config('tenants.trial_days');
            $hours = max(1, (int) round(config('auth.verification.expire', 60) / 60));
            $hoursLabel = $hours > 1 ? 'heures' : 'heure';

            return (new MailMessage)
                ->subject('Confirmez votre adresse email')
                ->greeting("Bonjour {$notifiable->name},")
                ->line("Bienvenue sur {$appName}. Confirmez votre adresse email pour activer votre espace et démarrer votre essai gratuit de {$trialDays} jours.")
                ->action('Confirmer mon adresse email', $url)
                ->line("Ce lien est valable {$hours} {$hoursLabel}. Passé ce délai, vous pourrez en demander un nouveau.")
                ->line("Si vous n'êtes pas à l'origine de cette inscription, ignorez simplement cet email.")
                ->salutation("L'équipe {$appName}");
        });
    }


        private function configureResetPasswordEmail(): void
    {
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $appName = config('app.name');
            $minutes = (int) config('auth.passwords.users.expire', 60);
            $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

            return (new MailMessage)
                ->subject('Réinitialisation de votre mot de passe')
                ->greeting("Bonjour {$notifiable->name},")
                ->line("Vous avez demandé à choisir un nouveau mot de passe pour votre compte {$appName}.")
                ->action('Choisir un nouveau mot de passe', $url)
                ->line("Ce lien est valable {$minutes} minutes et ne peut être utilisé qu'une seule fois.")
                ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez cet email : votre mot de passe actuel reste valable.")
                ->salutation("L'équipe {$appName}");
        });
    }

    private function emailKey(Request $request): string
    {
        return sha1(mb_strtolower(trim((string) $request->input('email'))));
    }
}