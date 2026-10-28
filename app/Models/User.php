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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;
use Althinect\FilamentSpatieRolesPermissions\Concerns\HasSuperAdmin;
use App\Notifications\ResetPassword;


class User extends Authenticatable implements FilamentUser, MustVerifyEmail, HasAvatar, HasName, HasMedia
{
    
    use HasRoles;
    use InteractsWithMedia;
    use HasApiTokens, HasFactory;
    use Notifiable;

    /*
    | Tabel-tabel ini memakai ON DELETE CASCADE ke users: menghapus user ikut
    | menghapus expense, advance, invoice, cuti, aset, dst. miliknya. User yang
    | punya data seperti itu tidak bisa dihapus — nonaktifkan saja (is_active).
    */
    private const OWNED_RECORDS = [
        'expenses'               => 'user_id',
        'advances'               => 'user_id',
        'invoices'               => 'user_id',
        'realisasi_batches'      => 'uploaded_by',
        'purchase_requests'      => 'requested_by',
        'absences'               => 'user_id',
        'assets'                 => 'penanggung_jawab_id',
        'performance_indicators' => 'user_id',
        'announcements'          => 'user_id',
        'budget_logs'            => 'user_id',
    ];

    protected static function booted(): void
    {
        // Berlaku untuk semua jalur (tombol hapus, hapus massal, kode): batal bila ada data.
        static::deleting(fn (User $user) => $user->hasBusinessRecords() ? false : null);
    }

    public function hasBusinessRecords(): bool
    {
        foreach (self::OWNED_RECORDS as $table => $column) {
            if (Schema::hasTable($table) && DB::table($table)->where($column, $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

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
        'telegram_linked_at' => 'datetime',
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

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
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
