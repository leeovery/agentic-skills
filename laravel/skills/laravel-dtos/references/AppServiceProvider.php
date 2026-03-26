<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerModelFactoryResolver();
    }

    /**
     * Register factory resolver for Data objects.
     *
     * This allows Factory::factoryForModel() to resolve Data test factories
     * from the correct namespace (Database\Factories\Data\).
     *
     * Classes ending in 'Data' → Database\Factories\Data\{ClassName}Factory
     * All other classes → Database\Factories\{ClassName}Factory
     */
    private function registerModelFactoryResolver(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName) {
            if (str($modelName)->endsWith('Data')) {
                return 'Database\Factories\Data\\'.Str::afterLast($modelName, '\\').'Factory';
            }

            return 'Database\Factories\\'.Str::afterLast($modelName, '\\').'Factory';
        });
    }
}
