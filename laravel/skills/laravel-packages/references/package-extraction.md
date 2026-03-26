# Package Extraction - Complete Guide

Extract **reusable patterns** into packages when they provide value across multiple projects. This guide covers when to extract, how to structure packages, and best practices for maintaining them.

**Related guides:**
- [Actions](../../laravel-actions/SKILL.md) - Action pattern for package base classes
- [DTOs](../../laravel-dtos/SKILL.md) - DTO patterns for package data structures
- [service-providers.md](../../laravel-providers/references/service-providers.md) - Service provider structure for packages
- [code-style.md](../../laravel-quality/references/code-style.md) - Code style for package consistency

## Philosophy

Package extraction should be:
- **Deliberate** - Extract patterns used across multiple projects
- **Mature** - Only extract stable, battle-tested code
- **Valuable** - Provides genuine reusability and time savings
- **Maintainable** - Clear API, good documentation, comprehensive tests
- **Minimal** - Few dependencies, focused scope

## When to Extract

### Extract to Package When

✅ **Used in 3+ projects**
- Pattern has proven useful across multiple codebases
- Implementation is consistent across projects
- Saves significant time when starting new projects

✅ **Well-tested and battle-hardened**
- Code has been used in production
- Edge cases have been discovered and handled
- No major bugs or design flaws

✅ **Self-contained with minimal dependencies**
- Clear boundaries and responsibilities
- Doesn't rely on project-specific logic
- Works with standard Laravel dependencies

✅ **Clear public API**
- Well-defined interface
- Backward compatibility can be maintained
- Easy to document and explain

### Don't Extract When

❌ **Still evolving rapidly**
- API changes frequently
- Design patterns not settled
- Different projects need different variations

❌ **Project-specific logic**
- Tightly coupled to specific business requirements
- Contains domain-specific assumptions
- Wouldn't make sense in other contexts

❌ **Tightly coupled to application code**
- Depends on specific models or database structure
- Requires extensive configuration per project
- Hard to abstract into generic interface

❌ **Small utility functions**
- Better as traits or helper functions
- Overhead of package not worth the value
- Copy-paste is actually simpler

## Package Structure

### Directory Organization

```
my-package/
├── .github/
│   └── workflows/
│       └── tests.yml
├── config/
│   └── my-package.php          # Package configuration
├── src/
│   ├── Actions/                # Invokable action classes
│   ├── Contracts/              # Interfaces
│   ├── Data/                   # DTOs (if using Spatie Data)
│   ├── Exceptions/             # Custom exceptions
│   ├── Facades/                # Facades
│   ├── Support/                # Helper classes
│   └── MyPackageServiceProvider.php
├── tests/
│   ├── Feature/                # Integration tests
│   ├── Unit/                   # Unit tests
│   ├── Pest.php                # Pest configuration
│   └── TestCase.php            # Base test case
├── .gitignore
├── CHANGELOG.md                # Version changelog
├── composer.json               # Package dependencies
├── LICENSE.md                  # License
├── phpunit.xml or phpunit.xml.dist
└── README.md                   # Documentation
```

### Composer Configuration

```json
{
  "name": "yourname/my-package",
  "description": "Brief description of what the package does",
  "keywords": ["laravel", "package", "relevant", "keywords"],
  "license": "MIT",
  "authors": [
    {
      "name": "Your Name",
      "email": "your.email@example.com"
    }
  ],
  "require": {
    "php": "^8.4",
    "illuminate/support": "^11.0"
  },
  "require-dev": {
    "orchestra/testbench": "^9.0",
    "pestphp/pest": "^3.0",
    "pestphp/pest-plugin-laravel": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "YourName\\MyPackage\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "YourName\\MyPackage\\Tests\\": "tests/"
    }
  },
  "extra": {
    "laravel": {
      "providers": [
        "YourName\\MyPackage\\MyPackageServiceProvider"
      ],
      "aliases": {
        "MyPackage": "YourName\\MyPackage\\Facades\\MyPackage"
      }
    }
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

## Service Provider

### Basic Service Provider

**[View full implementation →](./ExampleServiceProvider.php)**

### Advanced Service Provider

For more advanced configurations with multiple boot methods, command registration, and macro support, refer to the implementation above with enhanced organization using private boot methods.

## Package Base Classes

### Action Base Class

**[View full implementation →](./ExampleAction.php)**

**Usage in applications:**

```php
use YourName\MyPackage\Action;

class ProcessPaymentAction extends Action
{
    public function __invoke(PaymentData $data): Payment
    {
        // Implementation
    }
}

// Use it
ProcessPaymentAction::run($paymentData);
```

### Data Base Class

**[View full implementation →](./ExampleBaseData.php)**

## Testing Packages

### Pest Configuration

```php
<?php

declare(strict_types=1);

use YourName\MyPackage\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
```

### Base Test Case

```php
<?php

declare(strict_types=1);

namespace YourName\MyPackage\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use YourName\MyPackage\MyPackageServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MyPackageServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('my-package.option', 'value');
    }
}
```

### Unit Tests

```php
<?php

declare(strict_types=1);

use YourName\MyPackage\Actions\ProcessAction;

it('processes data correctly', function () {
    $result = ProcessAction::run($inputData);

    expect($result)
        ->toBeInstanceOf(ProcessedData::class)
        ->status->toBe('completed');
});
```

## Facades

### Creating a Facade

```php
<?php

declare(strict_types=1);

namespace YourName\MyPackage\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed process(array $data)
 * @method static bool validate(array $data)
 *
 * @see \YourName\MyPackage\MyPackageManager
 */
class MyPackage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MyPackageManager::class;
    }
}
```

### Using the Facade

```php
use YourName\MyPackage\Facades\MyPackage;

$result = MyPackage::process($data);
```

## Configuration Files

### Package Configuration

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default driver used by the package.
    |
    */
    'default' => env('MY_PACKAGE_DRIVER', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the drivers for the package.
    |
    */
    'drivers' => [
        'default' => [
            'key' => env('MY_PACKAGE_KEY'),
            'secret' => env('MY_PACKAGE_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Options
    |--------------------------------------------------------------------------
    |
    | Additional configuration options.
    |
    */
    'timeout' => 30,
    'retry_attempts' => 3,
];
```

## Documentation

### README Structure

```markdown
# Package Name

Brief description of what the package does.

## Installation

```bash
composer require yourname/my-package
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=my-package-config
```

## Usage

### Basic Usage

```php
use YourName\MyPackage\Facades\MyPackage;

$result = MyPackage::process($data);
```

### Advanced Usage

Detailed examples of more complex scenarios.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover security issues, please email security@example.com.

## Credits

- [Your Name](https://github.com/yourname)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
```

## Example Packages

### Action Pattern Package

**Package structure for reusable action pattern:**

```
laravel-actions/
├── src/
│   ├── Action.php              # Base action class
│   ├── Concerns/
│   │   ├── AsAction.php        # Trait for action behavior
│   │   └── MocksActions.php    # Testing utilities
│   └── ActionsServiceProvider.php
└── tests/
    └── ActionTest.php
```

### Data Pattern Package

**Package for DTO utilities:**

```
laravel-data-extras/
├── src/
│   ├── Concerns/
│   │   ├── HasTestFactory.php  # Test factory trait
│   │   └── ValidatesData.php   # Validation utilities
│   ├── Formatters/
│   │   ├── MoneyFormatter.php
│   │   └── DateFormatter.php
│   └── DataExtrasServiceProvider.php
└── tests/
```

### Service Integration Package

**Package for external service integration:**

```
stripe-integration/
├── src/
│   ├── StripeManager.php
│   ├── Contracts/
│   │   └── StripeDriverInterface.php
│   ├── Drivers/
│   │   ├── StripeDriver.php
│   │   └── NullDriver.php
│   ├── Facades/
│   │   └── Stripe.php
│   └── StripeServiceProvider.php
└── tests/
```

## Versioning

### Semantic Versioning

Follow [SemVer](https://semver.org/) strictly:

- **MAJOR** version for incompatible API changes
- **MINOR** version for backward-compatible functionality
- **PATCH** version for backward-compatible bug fixes

### Version Examples

```
1.0.0   - Initial release
1.1.0   - Added new feature (backward-compatible)
1.1.1   - Bug fix (backward-compatible)
2.0.0   - Breaking change (new API)
```

### Changelog Format

```markdown
# Changelog

## [2.0.0] - 2024-03-15

### Breaking Changes
- Changed Action::execute() to Action::__invoke()
- Removed deprecated ProcessAction class

### Added
- New ValidationAction base class
- Support for async processing

### Fixed
- Fixed memory leak in ProcessAction

## [1.1.0] - 2024-02-01

### Added
- Added retry mechanism to Action base class
```

## Publishing & Distribution

### GitHub Release

1. Tag the release: `git tag -a v1.0.0 -m "Release v1.0.0"`
2. Push tags: `git push origin v1.0.0`
3. Create GitHub release with changelog

### Packagist

1. Register package on [Packagist](https://packagist.org/)
2. Configure GitHub webhook for auto-updates
3. Ensure composer.json is properly configured

## Key Principles

1. **Start with Copy-Paste**: Reuse code across projects before extracting
2. **Three Project Rule**: Extract after using in 3+ projects
3. **Semantic Versioning**: Follow SemVer strictly for predictable upgrades
4. **Excellent Documentation**: Write clear, comprehensive docs with examples
5. **Comprehensive Tests**: High test coverage for reliability
6. **Minimal Dependencies**: Keep dependencies to minimum
7. **Backward Compatibility**: Maintain BC within major versions
8. **Clear Migration Path**: Provide upgrade guides for breaking changes

## Common Pitfalls

### Over-Extraction

❌ **Don't extract too early**
- Wait until pattern is proven in multiple projects
- Avoid extracting code that's still evolving

### Under-Documentation

❌ **Don't skip documentation**
- Clear README with usage examples
- Docblocks on all public methods
- Migration guides for major versions

### Tight Coupling

❌ **Don't couple to specific implementations**
- Use contracts/interfaces
- Avoid depending on specific model structures
- Make configuration flexible

### Poor Testing

❌ **Don't skip tests**
- Comprehensive test coverage
- Test edge cases
- Include integration tests

## Summary

**Package extraction should:**
- Solve a real, recurring problem
- Be mature and battle-tested
- Have minimal dependencies
- Follow semantic versioning
- Include excellent documentation
- Have comprehensive tests

**Package extraction should NOT:**
- Be done prematurely
- Include project-specific logic
- Have complex configuration requirements
- Skip proper documentation and testing

**See also:**
- [Actions](../../laravel-actions/SKILL.md) - Action pattern for base classes
- [service-providers.md](../../laravel-providers/references/service-providers.md) - Service provider structure
- [code-style.md](../../laravel-quality/references/code-style.md) - Code style guidelines
- [Quality](../../laravel-quality/SKILL.md) - Testing and quality standards
