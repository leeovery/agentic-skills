<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RequestDataProviderItem;

class MacroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerTestResponseMacros();
    }

    private function registerTestResponseMacros(): void
    {
        TestResponse::macro(
            'assertValidationErrors',
            function (RequestDataProviderItem $dataProviderItem): TestResponse {
                /* @var TestResponse $this */
                return $this
                    ->assertUnprocessable()
                    ->when(
                        filled($dataProviderItem->expectedError),
                        fn (TestResponse $test) => $test->assertInvalid([
                            $dataProviderItem->attribute => $dataProviderItem->expectedError,
                        ])
                    )
                    ->when(
                        filled($dataProviderItem->notExpectedError),
                        fn (TestResponse $test) => $test->assertValid($dataProviderItem->attribute)
                    );
            }
        );
    }
}
