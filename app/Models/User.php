<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @param string ...$roles any of which is sufficient — e.g.
     *                         hasRoleOn($merchant, 'owner', 'admin')
     */
    public function hasRoleOn(Merchant $merchant, string ...$roles): bool
    {
        $pivotRole = $this->merchants()->where('merchant_id', $merchant->id)->value('role');

        return in_array($pivotRole, $roles, true);
    }
}
