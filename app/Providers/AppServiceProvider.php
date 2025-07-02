<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $credentialsPath = storage_path('app/google/freezer-key.json');

        $base64 = config('services.google.credentials_b64');
        $decoded = base64_decode($base64, true);

        if (!$decoded || !json_decode($decoded)) {
            throw new \RuntimeException('Invalid base64 or JSON in GOOGLE_APPLICATION_CREDENTIALS_B64');
        }

        file_put_contents($credentialsPath, $decoded);
    }
}
