<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Ai\AiTranslationCache;
use App\Models\Attributes\Attribute;
use App\Models\Brands\Brand;
use App\Models\Categories\Category;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Products\Product;
use App\Services\Api\Ai\OpenAiTranslatorService;
use App\Supports\Services\Translations\Attribute\AttributeDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Brand\BrandNameAiTranslatorService;
use App\Supports\Services\Translations\Category\CategoryDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Manufacturer\ManufacturerNameAiTranslatorService;
use App\Supports\Services\Translations\Product\ProductNameAiTranslatorService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AiTranslationHashCacheServicesTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $container = new Application(__DIR__);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'database.db_prefix'         => '',
            'app.ai_translation_enabled' => false,
        ]));

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();
        $container->instance('db', self::$capsule->getDatabaseManager());

        $schema = self::$capsule->schema();

        $schema->create('products', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });

        $schema->create('brands', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });

        $schema->create('manufacturers', static function ($table): void {
            $table->increments('id');
            $table->timestamps();
        });

        $schema->create('ai_translation_caches', static function ($table): void {
            $table->increments('id');
            $table->string('translatable_type');
            $table->unsignedInteger('translatable_id');
            $table->string('hash', 64);
            $table->longText('prompt');
            $table->longText('answer');
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'hash'], 'ai_translation_caches_scope_hash_unique');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        app('config')->set('app.ai_translation_enabled', false);

        AiTranslationCache::query()->delete();
        Product::query()->delete();
        Attribute::query()->delete();
        Category::query()->delete();
        Brand::query()->delete();
        Manufacturer::query()->delete();
    }

    /**
     * @param  class-string  $service_class
     * @param  class-string  $entity_model_class
     */
    #[DataProvider('translatorProvider')]
    public function test_it_stores_and_reuses_translation_cache_for_unified_table(
        string $service_class,
        string $setter_method,
        string $entity_model_class,
        int $entity_id,
        string $source_text,
        string $expected_answer
    ): void {
        $entity_model_class::query()->insert(['id' => $entity_id]);

        $translator_service = $this->makeTranslatorWithoutRealAi($service_class);
        $translator_service->{$setter_method}($entity_id);

        $prompt = "Translate the following text from en to uk.\n\n{$source_text}";

        $first_answer  = $translator_service->translate($prompt);
        $second_answer = $translator_service->translate($prompt);

        self::assertSame($expected_answer, $first_answer);
        self::assertSame($first_answer, $second_answer);

        self::assertSame(
            1,
            AiTranslationCache::query()
                ->where('translatable_type', $entity_model_class)
                ->where('translatable_id', $entity_id)
                ->count()
        );

        $row = AiTranslationCache::query()
            ->where('translatable_type', $entity_model_class)
            ->where('translatable_id', $entity_id)
            ->first();

        self::assertInstanceOf(AiTranslationCache::class, $row);
        self::assertSame($expected_answer, (string) $row->answer);
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: class-string, 3: int, 4: string, 5: string}>
     */
    public static function translatorProvider(): array
    {
        return [
            'product_name' => [
                ProductNameAiTranslatorService::class,
                'setProductId',
                Product::class,
                1001,
                'Phone',
                'translated-to-uk-Phone',
            ],
            'attribute_description' => [
                AttributeDescriptionAiTranslatorService::class,
                'setAttributeId',
                Attribute::class,
                1002,
                'Color',
                'translated-to-uk-Color',
            ],
            'category_description' => [
                CategoryDescriptionAiTranslatorService::class,
                'setCategoryId',
                Category::class,
                1003,
                'Electronics',
                'translated-to-uk-Electronics',
            ],
            'brand_name' => [
                BrandNameAiTranslatorService::class,
                'setBrandId',
                Brand::class,
                1004,
                'Prime',
                'translated-to-uk-Prime',
            ],
            'manufacturer_name' => [
                ManufacturerNameAiTranslatorService::class,
                'setManufacturerId',
                Manufacturer::class,
                1005,
                'Acme',
                'translated-to-uk-Acme',
            ],
        ];
    }

    private function makeTranslatorWithoutRealAi(string $service_class): object
    {
        $ai_reflection = new ReflectionClass(OpenAiTranslatorService::class);
        $fake_ai       = $ai_reflection->newInstanceWithoutConstructor();

        return new $service_class($fake_ai);
    }
}
