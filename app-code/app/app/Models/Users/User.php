<?php

declare(strict_types=1);

namespace App\Models\Users;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Products\Imports\ProductImportBatch;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'lastname',
        'email',
        'email_verified_at',
        'telephone',
        'avatar',
        'is_active',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            // If password is null and it's being changed, prevent saving it
            if ($user->password === null && $user->isDirty('password')) {
                // Restore the original password value from database
                $user->password = $user->getOriginal('password');
            }
        });
    }

    public function telephone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : clear_telephone($value),
        );
    }

    /**
     * For \App\Filament\Resources\Users\UserResource
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->name.' '.($this->lastname ?? ''));
    }

    /**
     * For \App\Filament\Resources\Users\UserForm
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (empty($this->avatar)) {
            return null;
        }

        return Storage::url($this->avatar);
    }

    /**
     * @return HasMany<ProductImportBatch>
     */
    public function productImportBatches(): HasMany
    {
        return $this->hasMany(ProductImportBatch::class);
    }
}
