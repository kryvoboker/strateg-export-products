<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProcessProductImportBatchJobCategoryTreeTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        $schema = self::$capsule->schema();

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        $schema->create('shop_languages', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('shop_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('category_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_language_id']);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        CategoryDescription::query()->delete();
        Category::query()->delete();
        ShopLanguage::query()->delete();

        ShopLanguage::query()->create([
            'shop_id' => 1,
            'code' => 'uk',
            'name' => 'Українська',
            'is_active' => true,
        ]);
    }

    public function test_it_parses_multiple_category_paths_from_single_cell(): void
    {
        $job = new ProcessProductImportBatchJob(1);

        /** @var list<list<string>> $category_paths */
        $category_paths = $this->invokePrivateMethod(
            $job,
            'parseCategoryPathsFromRawValue',
            ['Побутова техніка, Побутова техніка > Клімат, Електроніка, Побутова техніка > Клімат']
        );

        self::assertSame(
            [
                ['Побутова техніка'],
                ['Побутова техніка', 'Клімат'],
                ['Електроніка'],
            ],
            $category_paths
        );
    }

    public function test_it_creates_and_reuses_category_tree_by_path(): void
    {
        $job = new ProcessProductImportBatchJob(1);

        $leaf_category_id = (int) $this->invokePrivateMethod(
            $job,
            'resolveOrCreateCategoryIdByPath',
            [['Смарт-пристрої', 'Розумний дім', 'Датчики']]
        );

        self::assertGreaterThan(0, $leaf_category_id);
        self::assertSame(3, Category::query()->count());
        self::assertSame(3, CategoryDescription::query()->count());

        $leaf_category = Category::query()->findOrFail($leaf_category_id);
        $mid_category = Category::query()->findOrFail((int) $leaf_category->parent_id);
        $root_category = Category::query()->findOrFail((int) $mid_category->parent_id);

        self::assertSame('Датчики', CategoryDescription::query()->where('category_id', $leaf_category->id)->value('name'));
        self::assertSame('Розумний дім', CategoryDescription::query()->where('category_id', $mid_category->id)->value('name'));
        self::assertSame('Смарт-пристрої', CategoryDescription::query()->where('category_id', $root_category->id)->value('name'));

        $same_leaf_category_id = (int) $this->invokePrivateMethod(
            $job,
            'resolveOrCreateCategoryIdByPath',
            [['Смарт-пристрої', 'Розумний дім', 'Датчики']]
        );

        self::assertSame($leaf_category_id, $same_leaf_category_id);
        self::assertSame(3, Category::query()->count());

        $another_leaf_category_id = (int) $this->invokePrivateMethod(
            $job,
            'resolveOrCreateCategoryIdByPath',
            [['Смарт-пристрої', 'Розумний дім', 'Освітлення']]
        );

        self::assertGreaterThan(0, $another_leaf_category_id);
        self::assertSame(4, Category::query()->count());

        $another_leaf = Category::query()->findOrFail($another_leaf_category_id);
        self::assertSame($mid_category->id, (int) $another_leaf->parent_id);
        self::assertSame('Освітлення', CategoryDescription::query()->where('category_id', $another_leaf->id)->value('name'));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);
        $method->setAccessible(true);

        return $method->invokeArgs($target, $arguments);
    }
}

