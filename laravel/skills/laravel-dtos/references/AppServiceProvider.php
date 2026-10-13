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
        $this->registerFactoryResolver();
    }

    private function registerFactoryResolver(): void
    {
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            $basename = Str::afterLast($modelName, '\\');

            if (str_ends_with($modelName, 'Data')) {
                return "Database\\Factories\\Data\\{$basename}Factory";
            }

            return "Database\\Factories\\{$basename}Factory";
        });
    }
}
