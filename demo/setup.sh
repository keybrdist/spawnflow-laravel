#!/usr/bin/env bash
# Provision the scratch app the tape films against. Reproducible starting state:
# a fresh Laravel app + a real posts table, spawnflow NOT yet installed.
set -euo pipefail
DIR="${1:-spawnflow-demo-app}"

composer create-project laravel/laravel "$DIR" --no-interaction
cd "$DIR"

cat > database/migrations/2026_01_01_000001_create_posts_table.php <<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->date('published_at')->nullable();
            $table->foreignId('ownerId')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
PHP

php artisan make:model Post
php artisan migrate --force

echo
echo "Ready. Film with:  cd $DIR && vhs <package>/demo/3-command-path.tape"
