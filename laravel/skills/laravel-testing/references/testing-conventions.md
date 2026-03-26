# Testing Conventions

Guide for structuring test files to match Laravel controller conventions with RESTful ordering.

**Related guides:**
- [testing.md](testing.md) - Complete testing guide with patterns and best practices
- [Controllers](../../laravel-controllers/SKILL.md) - Controller structure and RESTful method ordering
- [validation-testing.md](../../laravel-validation/references/validation-testing.md) - Form request validation testing

## When to Use This Structure

**RESTful ordering applies to:**
- Feature tests for controllers (HTTP endpoints)
- Tests that mirror RESTful operations

**Use common sense for:**
- Action tests (group by related functionality)
- Service tests (group by feature area)
- Unit tests (order by complexity or dependencies)

**When tests grow large**, use Pest's `describe()` to organize:

```php
describe('Calendar Management', function () {
    test('can create calendar');
    test('can update calendar');
});

describe('Calendar Sharing', function () {
    test('can share calendar with user');
    test('can revoke calendar access');
});
```

## Test Naming

Use descriptive phrases with the `test()` function. Tests should read as "test [can/does] [action]".

**✅ Good:**
```php
test('can list calendars');
test('can show calendar details');
test('can create calendar');
test('can update calendar name');
test('can delete calendar');
```

**❌ Bad:**
```php
test('lists calendars');
test('gets calendar by ID');
test('creates calendar');
```

## Test Ordering

Follow RESTful convention order (same as Laravel controller methods):

1. **Index** - Listing/filtering resources
2. **Show** - Getting single resource
3. **Store/Create** - Creating new resources
4. **Update** - Modifying existing resources
5. **Destroy/Delete** - Removing resources

Within each section, order from simple to complex:
- Basic functionality first
- Edge cases second
- Error handling last

## Example Structure

```php
// Index - List resources
test('can list calendars');
test('can list calendars with primary marked');
test('can list calendars with pagination');
test('can handle empty calendar list');

// Show - Get single resource
test('can show calendar by ID');
test('can show primary calendar');
test('can handle non-existent calendar');
test('can handle invalid calendar ID');

// Create - Create new resources
test('can create calendar with minimal fields');
test('can create calendar with all fields');
test('can create calendar with custom timezone');
test('can handle duplicate calendar names');

// Update - Modify resources
test('can update calendar name');
test('can update calendar timezone');
test('can update multiple fields');
test('can handle updating non-existent calendar');

// Delete - Remove resources
test('can delete calendar');
test('can handle deleting non-existent calendar');
test('can verify calendar deleted from provider');
```

## Grouping Large Test Files

For larger test files, use Pest's `describe()` to organize tests into logical groups:

```php
describe('List Calendars', function () {
    test('can list calendars');
    test('can list calendars with pagination');
    test('can handle empty calendar list');
});

describe('Show Calendar', function () {
    test('can show calendar by ID');
    test('can show primary calendar');
    test('can handle non-existent calendar');
});

describe('Create Calendar', function () {
    test('can create calendar with minimal fields');
    test('can create calendar with all fields');
    test('can create calendar with custom timezone');
});

describe('Update Calendar', function () {
    test('can update calendar name');
    test('can update calendar timezone');
    test('can update multiple fields');
});

describe('Delete Calendar', function () {
    test('can delete calendar');
    test('can handle deleting non-existent calendar');
});
```

## Authentication & Authorization Testing

Test that endpoints are properly protected before testing functionality.

### Testing Unauthenticated Access

Ensure endpoints require authentication using higher-order tests:

```php
// Authentication - ensure endpoints exist and fail when unauthenticated
postJson('/application')->assertUnauthorized();
getJson('/application/5633169c-2a4e-4861-9d93-7ae04e85fa06')->assertUnauthorized();
putJson('/application/5633169c-2a4e-4861-9d93-7ae04e85fa06')->assertUnauthorized();
patchJson('/application/5633169c-2a4e-4861-9d93-7ae04e85fa06')->assertUnauthorized();
deleteJson('/application/5633169c-2a4e-4861-9d93-7ae04e85fa06')->assertUnauthorized();
```

### Testing Authorization Policies

Ensure users can only access their own resources:

```php
test('cannot view other users calendars', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $calendar = Calendar::factory()->for($otherUser)->create();

    actingAs($user)
        ->getJson("/calendars/{$calendar->id}")
        ->assertForbidden();
});

test('cannot update other users calendars', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $calendar = Calendar::factory()->for($otherUser)->create();

    actingAs($user)
        ->putJson("/calendars/{$calendar->id}", ['name' => 'Updated'])
        ->assertForbidden();
});

test('cannot delete other users calendars', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $calendar = Calendar::factory()->for($otherUser)->create();

    actingAs($user)
        ->deleteJson("/calendars/{$calendar->id}")
        ->assertForbidden();
});
```

### Organizing Auth Tests

Group authentication/authorization tests at the top of test files, before functional tests:

```php
postJson('/calendars')->assertUnauthorized();
getJson('/calendars/123')->assertUnauthorized();
putJson('/calendars/123')->assertUnauthorized();
deleteJson('/calendars/123')->assertUnauthorized();

test('cannot view other users calendars', function () { /* ... */ });
test('cannot update other users calendars', function () { /* ... */ });
test('cannot delete other users calendars', function () { /* ... */ });

describe('List Calendars', function () {
    test('can list calendars');
    test('can list calendars with pagination');
});
```

## Integration Tests

For integration tests with external APIs:
- Use persistent test resources when possible
- Clean up created resources in `afterEach`
- Avoid creating/deleting calendars (quota limits)
- Prefer event-based tests (no quota restrictions)

---

**See also:**
- [testing.md](testing.md) - Triple-A pattern, mocking, factories, testing actions
- [Controllers](../../laravel-controllers/SKILL.md) - RESTful method ordering and conventions
- [validation-testing.md](../../laravel-validation/references/validation-testing.md) - Dataset-based validation testing
- [routing-permissions.md](../../laravel-routing/references/routing-permissions.md) - Route-level authorization configuration
