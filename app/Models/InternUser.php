<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\InternUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class InternUser extends Model
{
    /** @use HasFactory<InternUserFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone_number',
        'whatsapp_lid',
        'maganghub_email',
        'maganghub_password',
        'maganghub_auto_submit',
        'is_active',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'maganghub_auto_submit' => 'boolean',
            'maganghub_password' => 'encrypted',
        ];
    }

    /**
     * Get the session file storage path for this intern user.
     */
    public function getSessionFilePath(): string
    {
        return storage_path('app/maganghub_sessions/'.$this->phone_number.'.json');
    }

    /**
     * Get the Chrome user data directory path for this intern user.
     */
    public function getProfilePath(): string
    {
        return storage_path('app/maganghub_profiles/'.$this->phone_number);
    }

    /**
     * Check if user has an existing saved session file or profile.
     */
    public function hasMagangSession(): bool
    {
        return file_exists($this->getProfilePath()) || file_exists($this->getSessionFilePath());
    }

    /**
     * Check if user has credentials or an existing session file.
     */
    public function hasMagangCredentialsOrSession(): bool
    {
        return file_exists($this->getSessionFilePath())
            || (! empty($this->maganghub_email) && ! empty($this->maganghub_password));
    }

    /**
     * Clear the saved MagangHub session file, browser profile directory, and credentials.
     */
    public function clearMagangSession(bool $clearCredentials = true): bool
    {
        $sessionFile = $this->getSessionFilePath();
        if (file_exists($sessionFile)) {
            @unlink($sessionFile);
        }

        $profilePath = $this->getProfilePath();
        if (is_dir($profilePath)) {
            File::deleteDirectory($profilePath);
        }

        if ($clearCredentials) {
            $this->update([
                'maganghub_email' => null,
                'maganghub_password' => null,
            ]);
        }

        return true;
    }

    /**
     * Get the preferred WAHA Chat ID for messaging this user.
     * Prefers whatsapp_lid (@lid) if known to prevent duplicate message routing in WAHA/Baileys.
     */
    public function getPreferredChatId(): string
    {
        if (! empty($this->whatsapp_lid)) {
            return $this->whatsapp_lid.'@lid';
        }

        return $this->phone_number;
    }

    /**
     * @return HasMany<DailyReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(DailyReport::class, 'sender_phone', 'phone_number');
    }

    /**
     * Scope to only active users.
     *
     * @param  Builder<InternUser>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Normalize a phone number to digits (e.g. 0812 -> 62812).
     */
    public static function cleanPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (str_starts_with($digits, '08')) {
            $digits = '628'.substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Retrieve all active registered phone numbers (from database and .env configuration).
     *
     * @return list<string>
     */
    public static function getAllActivePhones(): array
    {
        // 1. From Database
        /** @var list<string> $dbPhones */
        $dbPhones = static::query()->active()->pluck('phone_number')->map(function ($phone) {
            return static::cleanPhoneNumber((string) $phone);
        })->toArray();

        // 2. From Config / .env (supporting comma-separated list)
        $envSources = array_filter([
            config('services.waha.user_phones'),
            config('services.waha.user_phone'),
        ]);

        $envPhones = [];
        foreach ($envSources as $envRaw) {
            $items = explode(',', (string) $envRaw);
            foreach ($items as $item) {
                $clean = static::cleanPhoneNumber(trim($item));
                if (! empty($clean)) {
                    $envPhones[] = $clean;
                }
            }
        }

        return array_values(array_unique(array_merge($dbPhones, $envPhones)));
    }

    /**
     * Check if a given phone number or whatsapp_lid is in the active registered list.
     */
    public static function isPhoneAuthorized(string $sender, ?string $rawFrom = null): bool
    {
        $clean = static::cleanPhoneNumber($sender);
        $allowed = static::getAllActivePhones();

        foreach ($allowed as $validPhone) {
            if ($clean === $validPhone ||
                str_ends_with($clean, substr($validPhone, -9)) ||
                str_ends_with($validPhone, substr($clean, -9))) {
                return true;
            }
        }

        // Also check if sender matches any active user's whatsapp_lid
        if (static::query()->active()->where('whatsapp_lid', $clean)->exists()) {
            return true;
        }

        // Check if candidate for auto-linking via LID
        if ($rawFrom && str_contains($rawFrom, '@lid')) {
            $user = static::findByPhoneOrLid($sender, $rawFrom);
            if ($user) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find user by phone number or whatsapp_lid.
     */
    public static function findByPhone(string $identifier, ?string $rawFrom = null): ?self
    {
        return static::findByPhoneOrLid($identifier, $rawFrom);
    }

    /**
     * Find user by phone number or whatsapp_lid.
     */
    public static function findByPhoneOrLid(string $identifier, ?string $rawFrom = null): ?self
    {
        $clean = static::cleanPhoneNumber($identifier);

        $user = static::query()
            ->where('phone_number', $clean)
            ->orWhere('phone_number', '08'.substr($clean, 3))
            ->orWhere('whatsapp_lid', $clean)
            ->first();

        if ($user) {
            return $user;
        }

        // If sender is an LID, try to auto-link to active intern user with pending report today
        if ($rawFrom && str_contains($rawFrom, '@lid')) {
            $today = Carbon::now('Asia/Makassar')->toDateString();
            $candidates = static::query()
                ->active()
                ->whereNull('whatsapp_lid')
                ->whereHas('reports', function ($q) use ($today) {
                    $q->whereDate('report_date', $today)
                        ->where('status', DailyReport::STATUS_PENDING_INPUT);
                })
                ->get();

            if ($candidates->count() === 1) {
                $linkedUser = $candidates->first();
                $linkedUser->update(['whatsapp_lid' => $clean]);
                Log::info("InternUser: Automatically linked LID {$clean} to user {$linkedUser->name} ({$linkedUser->phone_number})");

                return $linkedUser;
            }
        }

        return null;
    }
}
