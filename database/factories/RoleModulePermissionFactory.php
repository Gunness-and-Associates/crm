<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Support\Acl\AccessLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleModulePermission>
 */
class RoleModulePermissionFactory extends Factory
{
    protected $model = RoleModulePermission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role_id' => Role::factory(),
            'module_key' => fake()->unique()->word(),
            'view' => AccessLevel::None,
            'list' => AccessLevel::None,
            'edit' => AccessLevel::None,
            'delete' => AccessLevel::None,
            'import' => AccessLevel::None,
            'export' => AccessLevel::None,
            'mass_update' => AccessLevel::None,
        ];
    }
}
