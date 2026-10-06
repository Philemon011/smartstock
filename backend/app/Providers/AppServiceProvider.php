<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
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
    }

    private function emailKey(Request $request): string
    {
        return sha1(mb_strtolower(trim((string) $request->input('email'))));
    }
}