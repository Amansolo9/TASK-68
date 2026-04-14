<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Services\Guards\SignedSessionGuard;
use App\Models\Appointment;
use App\Models\ConsultationTicket;
use App\Policies\AppointmentPolicy;
use App\Policies\TicketPolicy;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::extend('signed-session', function ($app, $name, array $config) {
            $provider = Auth::createUserProvider($config['provider']);
            return new SignedSessionGuard($name, $provider, $app->make('request'));
        });

        // Register policies
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(ConsultationTicket::class, TicketPolicy::class);
    }
}
