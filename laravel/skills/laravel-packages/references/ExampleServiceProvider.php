<?php

declare(strict_types=1);

namespace YourName\MyPackage;

use Illuminate\Support\ServiceProvider;

class MyPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/my-package.php',
            'my-package'
        );

        // Register bindings
        $this->app->singleton(MyPackageManager::class, function ($app) {
            return new MyPackageManager($app['config']['my-package']);
        });
    }

    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/my-package.php' => config_path('my-package.php'),
        ], 'my-package-config');

        // Publish migrations (if applicable)
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'my-package-migrations');
    }
}
