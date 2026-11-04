<?php

namespace Mainstay\Auth;

use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/*
 | Someone who signs in to the admin. Eloquent, which a Laravel user provider
 | wants; content goes on being the query layer's. Not Authorizable, whose
 | can() asks the host's Gate and its callbacks: what this user may do is
 | asked of Mainstay's, or of holds() here.
 */
class User extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable, CanResetPassword, Notifiable;

    protected $table = 'mainstay_users';

    protected $fillable = ['name', 'email', 'password', 'role_id', 'grants', 'denials'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['grants' => '[]', 'denials' => '[]'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'grants' => 'array', 'denials' => 'array'];
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (string $email) => Str::lower($email));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /* The capabilities of the token this account came in with over the API,
       none for the admin's session. On this instance alone, never saved. */
    private ?array $limit = null;

    public function limitedTo(array $capabilities): static
    {
        $this->limit = $capabilities;

        return $this;
    }

    /*
     | Whether any of these names is held: denied none of them, and granted
     | one, or holding it or `*` through the role -- `*` is a role's, not a
     | grant's. A check names a type's own capability and its all-types form
     | together, so a denial of either wins over both.
     |
     | Through a token, the token holds one of them too, or `*`: what the two
     | share, so an account losing a capability takes it off its tokens.
     */
    public function holds(string ...$names): bool
    {
        if (array_intersect($names, $this->denials) !== []) {
            return false;
        }

        if ($this->limit !== null && ! in_array('*', $this->limit, true) && array_intersect($names, $this->limit) === []) {
            return false;
        }

        return in_array('*', $this->role->capabilities, true) || array_intersect($names, [...$this->grants, ...$this->role->capabilities]) !== [];
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
