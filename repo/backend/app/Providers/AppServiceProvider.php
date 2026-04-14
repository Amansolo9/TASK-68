<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AuditService;
use App\Services\EncryptionService;
use App\Services\SessionTokenService;
use App\Services\CaptchaService;
use App\Services\MaskingService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->singleton(EncryptionService::class);
        $this->app->singleton(SessionTokenService::class);
        $this->app->singleton(CaptchaService::class);
        $this->app->singleton(MaskingService::class);
    }

    public function boot(): void
    {
        //
    }
}
