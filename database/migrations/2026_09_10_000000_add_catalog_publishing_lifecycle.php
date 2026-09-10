<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->withoutSqliteForeignKeys(fn () => $this->migrateUp());
    }

    private function migrateUp(): void
    {
        $publishedAt = now();

        Schema::table('service_categories', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->change();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->index(['is_active', 'display_order', 'id'], 'categories_public_order_index');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->text('description_id')->nullable()->change();
            $table->text('description_en')->nullable()->change();
            $table->text('long_description_id')->nullable()->change();
            $table->text('long_description_en')->nullable()->change();
            $table->string('image_path')->nullable()->change();
            $table->json('gallery_paths')->default('[]')->change();
            $table->unsignedInteger('popularity')->default(0)->change();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->index(['is_active', 'display_order', 'id'], 'services_public_order_index');
        });

        Schema::table('service_variants', fn (Blueprint $table) => $table->timestamp('published_at')->nullable()->index());
        Schema::table('service_extensions', fn (Blueprint $table) => $table->timestamp('published_at')->nullable()->index());

        DB::table('service_categories')->update(['published_at' => $publishedAt]);
        DB::table('services')->update(['published_at' => $publishedAt]);
        DB::table('service_variants')->update(['published_at' => $publishedAt]);
        DB::table('service_extensions')->update(['published_at' => $publishedAt]);
    }

    public function down(): void
    {
        $this->withoutSqliteForeignKeys(fn () => $this->migrateDown());
    }

    private function migrateDown(): void
    {
        Schema::table('service_extensions', function (Blueprint $table): void {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });

        Schema::table('service_variants', function (Blueprint $table): void {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });

        foreach (['description_id', 'description_en', 'long_description_id', 'long_description_en', 'image_path'] as $column) {
            DB::table('services')
                ->whereNull('published_at')
                ->whereNull($column)
                ->update([$column => '']);
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex('services_public_order_index');
            $table->dropIndex(['published_at']);
            $table->dropColumn(['lock_version', 'published_at']);
            $table->text('description_id')->nullable(false)->change();
            $table->text('description_en')->nullable(false)->change();
            $table->text('long_description_id')->nullable(false)->change();
            $table->text('long_description_en')->nullable(false)->change();
            $table->string('image_path')->nullable(false)->change();
            $table->json('gallery_paths')->default(null)->change();
            $table->unsignedInteger('popularity')->default(null)->change();
        });

        DB::table('service_categories')
            ->whereNull('published_at')
            ->whereNull('image_path')
            ->update(['image_path' => '']);

        Schema::table('service_categories', function (Blueprint $table): void {
            $table->dropIndex('categories_public_order_index');
            $table->dropIndex(['published_at']);
            $table->dropColumn(['lock_version', 'published_at']);
            $table->string('image_path')->nullable(false)->change();
        });
    }

    private function withoutSqliteForeignKeys(Closure $callback): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $callback();

            return;
        }

        Schema::withoutForeignKeyConstraints($callback);
    }
};
