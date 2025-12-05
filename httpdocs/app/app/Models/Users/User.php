<?php

declare(strict_types=1);

namespace App\Models\Users;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
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
            'is_active'         => 'boolean',
            'password'          => 'hashed',
        ];
    }

    /**
     * @return Attribute
     */
    public function telephone(): Attribute
    {
        return Attribute::make(
            set: fn(?string $value) => $value === null ? null : clear_telephone($value),
        );
    }

    /**
     * For \App\Filament\Resources\Users\UserResource
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . ($this->lastname ?? ''));
    }

    /**
     * For \App\Filament\Resources\Users\UserForm
     *
     * @return string|null
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (empty($this->avatar)) {
            return null;
        }

        return Storage::url($this->avatar);
    }
}
