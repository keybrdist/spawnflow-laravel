<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spawnflow\Generator\TableIntrospector;

// Real-driver introspection coverage: sqlite is close to typeless, so
// enum columns, tinyint(1) booleans, decimals, and FK constraints are
// only honestly testable against MySQL. Runs when SPAWNFLOW_MYSQL_* env
// is present (the CI mysql service); skips locally otherwise.

beforeEach(function (): void {
    $host = env('SPAWNFLOW_MYSQL_HOST');
    if (! $host) {
        $this->markTestSkipped('MySQL introspection tests need SPAWNFLOW_MYSQL_HOST.');
    }

    config()->set('database.connections.mysql_introspection', [
        'driver' => 'mysql',
        'host' => $host,
        'port' => env('SPAWNFLOW_MYSQL_PORT', 3306),
        'database' => env('SPAWNFLOW_MYSQL_DATABASE', 'spawnflow_test'),
        'username' => env('SPAWNFLOW_MYSQL_USERNAME', 'root'),
        'password' => env('SPAWNFLOW_MYSQL_PASSWORD', 'root'),
    ]);
    $schema = Schema::connection('mysql_introspection');
    $schema->dropIfExists('intro_orders');
    $schema->dropIfExists('intro_customers');

    DB::connection('mysql_introspection')->statement('CREATE TABLE intro_customers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL
    )');

    DB::connection('mysql_introspection')->statement("CREATE TABLE intro_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id BIGINT UNSIGNED NOT NULL,
        customer_id BIGINT UNSIGNED NOT NULL,
        reference VARCHAR(64) NOT NULL,
        notes TEXT NULL,
        state ENUM('pending', 'paid', 'shipped') NOT NULL DEFAULT 'pending',
        is_priority TINYINT(1) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL,
        shipped_on DATE NULL,
        meta JSON NULL,
        created_at TIMESTAMP NULL,
        updated_at TIMESTAMP NULL,
        CONSTRAINT fk_intro_orders_customer FOREIGN KEY (customer_id) REFERENCES intro_customers (id)
    )");
});

afterEach(function (): void {
    if (env('SPAWNFLOW_MYSQL_HOST')) {
        Schema::connection('mysql_introspection')->dropIfExists('intro_orders');
        Schema::connection('mysql_introspection')->dropIfExists('intro_customers');
    }
});

test('MySQL column types map to the right descriptors', function (): void {
    $plan = (new TableIntrospector)->introspect('intro_orders', 'mysql_introspection');

    expect($plan['lines'])
        ->toContain("Field::string('reference')->rules('required')")
        ->toContain("Field::text('notes')->nullable()")
        ->toContain("Field::string('state')->rules('in:pending,paid,shipped')")
        ->toContain("Field::bool('is_priority')")
        ->toContain("Field::float('total')->rules('required')")
        ->toContain("Field::date('shipped_on')->nullable()")
        ->toContain("Field::json('meta')->nullable()")
        // FK without a matching model class falls back honestly.
        ->toContain("Field::int('customer_id') /* FK to intro_customers")
        // auto-increment PK and ownership column never appear.
        ->not->toContain("Field::int('id')")
        ->not->toContain("'owner_id')");

    expect($plan['names'])->not->toContain('id', 'owner_id', 'created_at')
        ->and($plan['visible'])->toContain('id', 'created_at', 'updated_at');
})->group('mysql-introspection');
