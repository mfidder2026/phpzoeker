<?php

namespace App\Filament\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Rolcontroles voor menu en pagina's (§5, §15.2). De rechten op records zelf staan in policies.
 */
final class Access
{
    public static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public static function isBeheerder(): bool
    {
        return self::user()?->isBeheerder() ?? false;
    }

    public static function isStaff(): bool
    {
        return self::user()?->isStaff() ?? false;
    }

    /**
     * @param  list<Role>  $roles
     */
    public static function hasAny(array $roles): bool
    {
        return self::user()?->hasAnyRole(array_map(fn (Role $role): string => $role->value, $roles)) ?? false;
    }
}
