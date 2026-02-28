<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Supports\Services\SeoSlug\ProductSeoKeywordService;
use PHPUnit\Framework\TestCase;

class ProductSeoKeywordServiceTest extends TestCase
{
    public function test_it_generates_language_specific_keywords_and_suffixes(): void
    {
        $service = new ProductSeoKeywordService();

        self::assertSame(
            'futbolka-chervona-z-bilymy-kruzhechkamy',
            $service->make('Футболка червона з білими кружечками', 'uk')
        );

        $en_keyword = $service->make('Футболка червона з білими кружечками', 'en');
        self::assertNotSame('', $en_keyword);
        self::assertStringEndsWith('-en', $en_keyword);

        $ru_keyword = $service->make('Футболка червона з білими кружечками', 'ru');
        self::assertNotSame('', $ru_keyword);
        self::assertStringEndsWith('-ru', $ru_keyword);

        self::assertSame(
            'fussball-hemd-de',
            $service->make('Fußball hemd', 'de')
        );
    }

    public function test_it_maps_ua_code_to_uk_and_handles_empty_language_code_without_suffix(): void
    {
        $service = new ProductSeoKeywordService();

        self::assertSame('nazva-tovaru', $service->make('Назва товару', 'ua'));
        self::assertSame('nazva-tovaru', $service->make('Назва товару', ''));
    }
}
