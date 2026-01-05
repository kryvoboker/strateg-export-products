<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait;

use App\Models\Slug;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

trait ProcessSlugsTrait
{
    /**
     * @return bool
     */
    protected function updateOrCreateSlugs(): bool
    {
        foreach ($this->slugs as $language_id => $slug_data) {
            if (empty($slug_data['name'])) {
                continue;
            }

            try {
                $this->record->slugs()->updateOrCreate(
                    ['language_id' => (int)$language_id],
                    ['slug' => $slug_data['name']]
                );
            } catch (Exception $e) {
                Log::channel('stack')->error($e->getMessage());

                Notification::make()
                    ->title(__('admin/default.errors.title'))
                    ->body(__('admin/default.errors.create_or_update_slugs_failed'))
                    ->danger()
                    ->send();

                return false;
            }
        }

        return true;
    }

    /**
     * @param array $data
     *
     * @return void
     */
    protected function getSlugs(array &$data): void
    {
        // Load slugs
        $slugs = $this->record->slugs()
            ->get()
            ->keyBy('language_id')
            ->map(fn(Slug $slug): array => [
                'language_id' => $slug->language_id,
                'name'        => $slug->slug,
            ])
            ->toArray();

        $data['slugs'] = $slugs;
    }
}
