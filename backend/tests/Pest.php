<?php

use App\Models\Category;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Autentica al usuario vía JWT (guard "api") para los feature tests.
 */
function actingAsApi(User $user): TestCase
{
    return test()->actingAs($user, 'api');
}

/**
 * Crea la taxonomía mínima (categoría + prioridades) necesaria para crear tickets.
 *
 * @return array{category: Category, low: Priority, high: Priority}
 */
function seedTaxonomy(): array
{
    return [
        'category' => Category::factory()->create(['name' => 'Hardware']),
        'low' => Priority::factory()->low()->create(),
        'high' => Priority::factory()->high()->create(),
    ];
}
