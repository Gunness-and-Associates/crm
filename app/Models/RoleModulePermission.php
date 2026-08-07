<?php

namespace App\Models;

use App\Support\Acl\AccessLevel;
use Database\Factories\RoleModulePermissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (role, module) — the seven actions each store a named
 * AccessLevel (BACKEND_BRIEF §8.1). Auto-created (all 'none') whenever a
 * module is registered — see AppServiceProvider.
 *
 * @property string $id
 * @property string $role_id
 * @property string $module_key
 * @property AccessLevel $view
 * @property AccessLevel $list
 * @property AccessLevel $edit
 * @property AccessLevel $delete
 * @property AccessLevel $import
 * @property AccessLevel $export
 * @property AccessLevel $mass_update
 */
class RoleModulePermission extends Model
{
    /** @use HasFactory<RoleModulePermissionFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'role_id', 'module_key', 'view', 'list', 'edit', 'delete', 'import', 'export', 'mass_update',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'view' => AccessLevel::class,
            'list' => AccessLevel::class,
            'edit' => AccessLevel::class,
            'delete' => AccessLevel::class,
            'import' => AccessLevel::class,
            'export' => AccessLevel::class,
            'mass_update' => AccessLevel::class,
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function level(string $action): AccessLevel
    {
        $value = $this->getAttribute($action);

        return $value instanceof AccessLevel ? $value : AccessLevel::NotSet;
    }
}
