<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Shops\ShopLanguage;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProcessProductImportBatchJobCategoryTreeTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private static ?Application $container = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        self::$container = new Application(__DIR__);
        Container::setInstance(self::$container);
        Facade::setFacadeApplication(self::$container);
        self::$container->instance('config', new Repository([
            'database.db_prefix' => '',
        ]));
        self::$container->instance('translator', new Translator(new ArrayLoader(), 'en'));
        self::$container->instance('validator', new ValidationFactory(
            self::$container->make('translator'),
            self::$container
        ));

        $schema = self::$capsule->schema();

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
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
            $table->boolean('is_default')->default(false);
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
        Facade::clearResolvedInstances();
        Container::setInstance(self::$container);
        Facade::setFacadeApplication(self::$container);

        CategoryDescription::query()->delete();
        Category::query()->delete();
        ShopLanguage::withoutEvents(static function (): void {
            ShopLanguage::query()->delete();
        });

        ShopLanguage::query()->create([
            'shop_id'    => 1,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);
    }

    public function test_it_parses_multiple_category_paths_from_single_cell(): void
    {
        $job = new ProcessProductImportBatchJob(1);

        /** @var list<list<string>> $category_paths */
        $category_paths = $this->invokePrivateMethod(
            $job,
            'parseCategoryPathsFromRawValue',
            ['Побутова техніка | Побутова техніка > Клімат | Електроніка | Побутова техніка > Клімат']
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
            [['Smart devices', 'Smart home', 'Sensors']]
        );

        self::assertGreaterThan(0, $leaf_category_id);
        self::assertSame(3, Category::query()->count());
        self::assertSame(3, CategoryDescription::query()->count());

        $leaf_category = Category::query()->findOrFail($leaf_category_id);
        $mid_category  = Category::query()->findOrFail((int) $leaf_category->parent_id);
        $root_category = Category::query()->findOrFail((int) $mid_category->parent_id);

        self::assertSame('Sensors', CategoryDescription::query()->where('category_id', $leaf_category->id)->value('name'));
        self::assertSame('Smart home', CategoryDescription::query()->where('category_id', $mid_category->id)->value('name'));
        self::assertSame('Smart devices', CategoryDescription::query()->where('category_id', $root_category->id)->value('name'));

        $same_leaf_category_id = (int) $this->invokePrivateMethod(
            $job,
            'resolveOrCreateCategoryIdByPath',
            [['Smart devices', 'Smart home', 'Sensors']]
        );

        self::assertSame($leaf_category_id, $same_leaf_category_id);
        self::assertSame(3, Category::query()->count());

        $another_leaf_category_id = (int) $this->invokePrivateMethod(
            $job,
            'resolveOrCreateCategoryIdByPath',
            [['Smart devices', 'Smart home', 'Lighting']]
        );

        self::assertGreaterThan(0, $another_leaf_category_id);
        self::assertSame(4, Category::query()->count());

        $another_leaf = Category::query()->findOrFail($another_leaf_category_id);
        self::assertSame($mid_category->id, (int) $another_leaf->parent_id);
        self::assertSame('Lighting', CategoryDescription::query()->where('category_id', $another_leaf->id)->value('name'));
    }

    public function test_it_resolves_global_category_and_ignores_shop_scoped_duplicate_on_import(): void
    {
        $global_root = Category::query()->create([
            'family_ulid' => '01HGLOBALROOT000000000000001',
            'shop_id'     => null,
            'parent_id'   => null,
            'sort_order'  => 0,
            'is_active'   => true,
        ]);

        CategoryDescription::query()->create([
            'category_id'      => (int) $global_root->id,
            'shop_language_id' => null,
            'name'             => 'Root',
            'description'      => null,
            'h1_title'         => 'Root',
            'meta_title'       => 'Root',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        $shop_scoped_root = Category::query()->create([
            'family_ulid' => '01HGLOBALROOT000000000000001',
            'shop_id'     => 55,
            'parent_id'   => null,
            'sort_order'  => 0,
            'is_active'   => true,
        ]);

        CategoryDescription::query()->create([
            'category_id'      => (int) $shop_scoped_root->id,
            'shop_language_id' => null,
            'name'             => 'Root',
            'description'      => null,
            'h1_title'         => 'Root',
            'meta_title'       => 'Root',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        $job = new ProcessProductImportBatchJob(1);

        $resolved_root_id = (int) $this->invokePrivateMethod(
            $job,
            'resolveOrCreateCategoryIdByPath',
            [['Root']]
        );

        self::assertSame((int) $global_root->id, $resolved_root_id);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);

        return $method->invokeArgs($target, $arguments);
    }
}
