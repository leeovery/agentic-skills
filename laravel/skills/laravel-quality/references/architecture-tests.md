# Architecture Tests (Pest)

Enforce conventions with Pest architecture tests. Add these when appropriate for the project.

## Setup

`tests/Pest.php`
```php
pest()->extend(Tests\TestCase::class)->in('Feature', 'Unit');
```

## Core Architecture Tests

`tests/Architecture/ActionsTest.php`
```php
<?php

declare(strict_types=1);

arch('actions are invokable')
    ->expect('App\Actions')
    ->toHaveMethod('__invoke');

arch('actions live in Actions namespace')
    ->expect('App\Actions')
    ->toBeClasses()
    ->toOnlyBeUsedIn('App\Actions', 'App\Http', 'App\Jobs', 'App\Listeners');

arch('actions do not use models directly')
    ->expect('App\Actions')
    ->not->toUse('Illuminate\Database\Eloquent\Model');
```

`tests/Architecture/DataTest.php`
```php
<?php

declare(strict_types=1);

arch('data objects extend base Data class')
    ->expect('App\Data')
    ->toExtend('App\Data\Data')
    ->ignoring('App\Data\Data');

arch('data objects use constructor property promotion')
    ->expect('App\Data')
    ->toHaveConstructor();
```

`tests/Architecture/StrictTypesTest.php`
```php
<?php

declare(strict_types=1);

arch('app files declare strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('test files declare strict types')
    ->expect('Tests')
    ->toUseStrictTypes();
```

`tests/Architecture/ControllersTest.php`
```php
<?php

declare(strict_types=1);

arch('controllers do not use DB facade')
    ->expect('App\Http')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('controllers do not use models directly')
    ->expect('App\Http\Web\Controllers')
    ->not->toUse('App\Models');
```

`tests/Architecture/NamingTest.php`
```php
<?php

declare(strict_types=1);

arch('actions end with Action suffix')
    ->expect('App\Actions')
    ->toHaveSuffix('Action');

arch('data objects end with Data suffix')
    ->expect('App\Data')
    ->toHaveSuffix('Data')
    ->ignoring('App\Data\Data', 'App\Data\Concerns');

arch('exceptions end with Exception suffix')
    ->expect('App\Exceptions')
    ->toHaveSuffix('Exception')
    ->ignoring('App\Exceptions\Concerns');
```

`tests/Architecture/ModelsTest.php`
```php
<?php

declare(strict_types=1);

arch('models use custom query builders')
    ->expect('App\Models')
    ->toHaveMethod('newEloquentBuilder');

arch('models do not use local scopes')
    ->expect('App\Models')
    ->not->toHaveMethod('scope*');
```
