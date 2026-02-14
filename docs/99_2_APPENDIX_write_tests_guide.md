# Test Writing Guide

This guide defines standards for writing maintainable, meaningful tests using PHPUnit for FoldSnap.

## Purpose and Principles

Tests should:
- **Verify real behavior** - Test scenarios that can actually happen, not theoretical edge cases
- **Be precise and specific** - Assert exact values, not approximations
- **Avoid obvious redundancies** - Don't test WordPress core, third-party libraries, or framework code
- **Avoid code duplication** - Use helper functions and extract common patterns

## Test Organization

```
tests/
├── Unit/              # Isolated logic tests
├── Feature/           # Integration tests
├── Concerns/          # Reusable test traits
└── TestsUtils/        # Test utilities and helpers
```

**Unit tests:** Test individual methods in isolation. Fast, focused, no external dependencies.

**Feature tests:** Test complete workflows. May interact with WordPress functions, database, or filesystem.

## Writing Good Tests

**Test real scenarios:**
- Edge cases that can actually happen
- Business logic and domain rules
- WordPress integration points
- Database operations
- User permissions and capabilities

**Be precise:**
- Use `assertCount(2, $items)` not `assertTrue(count($items) > 1)`
- Use `assertSame('expected', $actual)` not `assertTrue($actual == 'expected')`
- Use specific assertions: `assertInstanceOf`, `assertArrayHasKey`, `assertStringContainsString`

**Avoid code duplication:**
- Use available test helpers for common operations
- Extract repeated code into helper methods or traits
- Create test utilities in `tests/TestsUtils/`

**Focus on your logic:**
- Test your implemented business logic, not WordPress/library internals
- Test through public interfaces, not private methods
- Avoid testing constants, enum values, or standard framework behavior

**Be pragmatic:**
- Focus on realistic scenarios that can actually occur
- Avoid edge cases that are prevented by type systems or validation
- If a scenario requires breaking PHP type safety or WordPress constraints, it's not worth testing

### Test Naming

**Pattern:** `test_{what}_{expected_outcome}`

Examples:
- `test_folder_creation_succeeds_with_valid_name()`
- `test_unauthorized_user_cannot_create_folder()`
- `test_media_assignment_updates_folder_relationship()`
- `test_folder_deletion_preserves_media_items()`

## PHPUnit Commands

Run tests using Composer scripts:

```bash
# Install WordPress test environment (requires MySQL and SVN)
composer phpunit-install

# Run all tests
composer phpunit
```

**First-time setup:**
1. Copy `tools/phpunit-install-config-sample.json` to `tools/phpunit-install-config.json`
2. Fill in your local MySQL credentials
3. Run `composer phpunit-install`
4. Run `composer phpunit`

## WordPress Testing Environment

Tests use WordPress test framework:
- Bootstrap file: `tests/bootstrap.php`
- Tests discover files ending in `Tests.php`
- WordPress functions available in test environment
- Database operations use WordPress `wpdb`

## Test Structure

```php
<?php

namespace FoldSnap\Tests\Unit\Folder;

use FoldSnap\Folder\Manager;
use PHPUnit\Framework\TestCase;

class ManagerTests extends TestCase
{
    public function test_folder_creation_succeeds_with_valid_name()
    {
        // Arrange
        $name = 'Documents';

        // Act
        $folder = Manager::create($name);

        // Assert
        $this->assertInstanceOf(Folder::class, $folder);
        $this->assertSame('Documents', $folder->getName());
    }
}
```

## Common Assertions

**Identity and equality:**
```php
$this->assertSame($expected, $actual);      // Strict comparison (===)
$this->assertEquals($expected, $actual);     // Loose comparison (==)
$this->assertTrue($condition);
$this->assertFalse($condition);
$this->assertNull($value);
```

**Types and instances:**
```php
$this->assertInstanceOf(ClassName::class, $object);
$this->assertIsString($value);
$this->assertIsArray($value);
$this->assertIsInt($value);
```

**Arrays and collections:**
```php
$this->assertCount(3, $array);
$this->assertEmpty($array);
$this->assertArrayHasKey('key', $array);
$this->assertContains('value', $array);
```

**Strings:**
```php
$this->assertStringContainsString('needle', $haystack);
$this->assertStringStartsWith('prefix', $string);
$this->assertStringEndsWith('suffix', $string);
$this->assertMatchesRegularExpression('/pattern/', $string);
```

**Files:**
```php
$this->assertFileExists($path);
$this->assertFileIsReadable($path);
$this->assertDirectoryExists($path);
```

## Testing WordPress Integration

**Testing capabilities:**
```php
$user = $this->factory()->user->create(['role' => 'administrator']);
wp_set_current_user($user);

$this->assertTrue(current_user_can('upload_files'));
```

**Testing hooks:**
```php
$callback_fired = false;
add_action('foldsnap_folder_created', function() use (&$callback_fired) {
    $callback_fired = true;
});

do_action('foldsnap_folder_created');
$this->assertTrue($callback_fired);
```

**Testing database operations:**
```php
global $wpdb;

$result = $wpdb->insert($wpdb->prefix . 'foldsnap_cache', [
    'folder_id' => 123,
    'status' => 'active'
]);

$this->assertSame(1, $result);
$this->assertSame(1, $wpdb->rows_affected);
```

## Mocking and Stubs

Use PHPUnit mocking when necessary:

```php
$manager_mock = $this->createMock(FolderManager::class);
$manager_mock->method('create')
    ->willReturn(true);

$result = $handler->process($manager_mock);
$this->assertTrue($result);
```

## Testing Exceptions

```php
$this->expectException(InvalidArgumentException::class);
$this->expectExceptionMessage('Invalid folder name');

$folder = Manager::create('');
```

## Test Data and Fixtures

Create test data in `tests/TestsUtils/`:

```php
class TestDataFactory
{
    public static function createFolder(array $overrides = []): Folder
    {
        $defaults = [
            'name'   => 'Test Folder',
            'parent' => 0,
            'order'  => 0,
        ];

        return Folder::create(array_merge($defaults, $overrides));
    }
}
```

## Best Practices

**Do:**
- Test one concept per test method
- Use descriptive test names
- Arrange-Act-Assert structure
- Clean up resources (files, database entries)
- Test both success and failure cases
- Test edge cases and boundary conditions

**Don't:**
- Test private methods directly
- Test WordPress core functionality
- Test third-party library internals
- Create overly complex test setups
- Use sleep() or time-dependent assertions
- Leave test data in filesystem or database

## Related Documentation

- **[PHPUnit Documentation](https://docs.phpunit.de/)** - Official PHPUnit guide
- **[WordPress Testing](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)** - WordPress PHPUnit handbook
- **[PHPUnit Assertions](https://docs.phpunit.de/en/11.5/assertions.html)** - Complete assertion reference
