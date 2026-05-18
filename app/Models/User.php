<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use Althinect\FilamentSpatieRolesPermissions\Concerns\HasSuperAdmin;


class User extends Authenticatable implements FilamentUser, MustVerifyEmail, HasAvatar, HasName, HasMedia
{
    
    use HasRoles;
    use InteractsWithMedia;
    use HasApiTokens, HasFactory;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'email',
        'firstname',
        'lastname',
        'password',
        'nik',
        'phone',
        'address',
        'position',
        'npwp',
        'division',
        'ktp',
        'file_npwp'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'push_subscription' => 'array',
        'push_enabled' => 'boolean',
    ];

    /**
     * Get push subscription as array
     */
    public function getPushSubscription(): ?array
    {
        $subscription = $this->push_subscription;
        if (is_string($subscription)) {
            $subscription = json_decode($subscription, true);
        }
        return $subscription ?: null;
    }

    public function getFilamentName(): string
    {
        return $this->username;
    }


    public function canAccessPanel(Panel $panel): bool
    {
        // if ($panel->getId() === 'admin') {
        //     return str_ends_with($this->email, '@yourdomain.com') && $this->hasVerifiedEmail();
        // }

        return true;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->getMedia('avatars')?->first()?->getUrl() ?? $this->getMedia('avatars')?->first()?->getUrl('thumb') ?? null;
    }

    // Define an accessor for the 'name' attribute
    public function getNameAttribute()
    {
        return "{$this->firstname} {$this->lastname}";
    }

    public function getFullNameAttribute() {
       return $this->firstname . ' ' . $this->lastname;
        }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name'));
    }

    public function registerMediaConversions(Media|null $media = null): void
        {
            $this->addMediaConversion('thumb')
                ->fit(Fit::Contain, 300, 300)
                ->nonQueued();
        }
        public function tasks()
        {
            return $this->belongsToMany(Task::class, 'task_user', 'user_id', 'task_id');
        }

        public function purchaseRequests()
        {
            return $this->hasMany(PurchaseRequest::class, 'requested_by');
        }

        
        public function expenses()
        {
            return $this->hasMany(Expense::class);
        }
        
        public function event()
        {
            return $this->hasMany(Event::class);
        }

        public function assets()
        {
            return $this->hasMany(Asset::class, 'penanggung_jawab_id');
        }

        public function absences()
        {
            return $this->hasMany(Absence::class);
        }

        public function advances()
        {
            return $this->hasMany(Advance::class);
        }

}
