<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait;

use App\Models\Settings\Language;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

trait LanguageTrait
{
    /**
     * @return int|null
     */
    protected static function getCurrentLanguageId(): ?int
    {
        $language = new Language();

        // Get current locale language ID (adjust based on your logic)
        $current_language_id = $language->getLanguageByCode(app()->getLocale())?->id;

        if ($current_language_id === null) {
            $current_language_id = $language->getDefaultLanguage()?->id;
        }

        return $current_language_id;
    }

    /**
     * @param int|null    $language_id
     * @param string|null $returned_value
     *
     * @return string|null
     */
    protected static function validateLanguageIdIsNotNull(?int $language_id, ?string $returned_value = null): ?string
    {
        if ($language_id === null) {
            Notification::make()
                ->title(__('admin/default.errors.title'))
                ->body(__('admin/default.errors.no_language'))
                ->danger()
                ->send();

            return $returned_value ?? '-';
        }

        return null;
    }

    /**
     * @return Collection<Language>
     */
    protected static function getAcriveLanguages(): Collection
    {
        return new Language()->getActiveLanguages();
    }

    /**
     * @param Collection<Language> $active_languages
     *
     * @return int|null
     */
    protected static function tryGetCurrentLanguageIdFromActiveLangs(Collection $active_languages): ?int
    {
        /** @var Language $language */
        $language            = $active_languages->where('is_default', true)->first();
        $current_language_id = $language?->id;

        if (($returned_value = self::validateLanguageIdIsNotNull($current_language_id)) !== null) {
            return $returned_value;
        }

        return $current_language_id;
    }
}
