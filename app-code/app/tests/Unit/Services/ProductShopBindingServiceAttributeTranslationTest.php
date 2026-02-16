<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductToAttribute;
use App\Models\Shops\ShopLanguage;
use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Supports\Services\Catalog\ProductShopBindingService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductShopBindingServiceAttributeTranslationTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'database.db_prefix' => '',
        ]));

        $container->instance(AiTranslationPromptBuilderService::class, new AiTranslationPromptBuilderService());
        $container->instance(\App\Supports\Services\Ai\AiTranslationService::class, new FakeAiTranslationService());

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        $schema = self::$capsule->schema();

        $schema->create('shop_languages', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('shop_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $schema->create('product_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'shop_language_id']);
        });

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('attribute_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('attribute_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_language_id']);
        });

        $schema->create('product_to_attributes', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('attribute_id')->nullable();
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('text', 3000)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_id', 'shop_language_id']);
        });

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
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

        $schema->create('category_product', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('category_id');
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        ProductToAttribute::query()->delete();
        AttributeDescription::query()->delete();
        Attribute::query()->delete();
        ProductDescription::query()->delete();
        ShopLanguage::query()->delete();
    }

    public function test_it_splits_pipe_attributes_and_translates_to_shop_languages_using_default_source_language(): void
    {
        $shop_id    = 10;
        $product_id = 101;

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Phone',
            'description'      => 'Smart phone',
            'meta_title'       => 'Phone',
            'meta_description' => 'Smart phone',
            'meta_keywords'    => 'phone',
        ]);

        $source_attribute = Attribute::query()->create([
            'parent_id'  => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        AttributeDescription::query()->create([
            'attribute_id'     => (int) $source_attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Color|Size',
        ]);

        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $source_attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'text'             => 'Red|XL',
        ]);

        $service = new ProductShopBindingService();

        $this->invokePrivateMethod(
            $service,
            'translateProductTextsForShopLanguages',
            [$product_id, $shop_id]
        );

        $en_attributes = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $en_language->id)
            ->orderBy('id')
            ->get();

        self::assertCount(2, $en_attributes);
        self::assertSame(['Red', 'XL'], $en_attributes->pluck('text')->all());

        $attribute_ids = $en_attributes
            ->pluck('attribute_id')
            ->map(static fn ($attribute_id): int => (int) $attribute_id)
            ->all();

        $en_names = AttributeDescription::query()
            ->whereIn('attribute_id', $attribute_ids)
            ->where('shop_language_id', (int) $en_language->id)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame(['Color', 'Size'], $en_names);

        $uk_names = AttributeDescription::query()
            ->whereIn('attribute_id', $attribute_ids)
            ->where('shop_language_id', (int) $uk_language->id)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame(['Color [uk]', 'Size [uk]'], $uk_names);

        $uk_attribute_texts = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        self::assertSame(['Red [uk]', 'XL [uk]'], $uk_attribute_texts);
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

final class FakeAiTranslationService
{
    public function attributeName(int $attribute_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productName(int $product_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productDescription(int $product_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productAttributeText(int $product_id, int $attribute_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    private function translateFromPrompt(string $prompt): string
    {
        preg_match('/ to ([a-z]{2})\\./i', $prompt, $target_matches);
        $target_language_code = strtolower((string) ($target_matches[1] ?? 'uk'));

        $text = (string) preg_replace('/^.*\\n\\n/s', '', $prompt);

        return $text.' ['.$target_language_code.']';
    }
}
