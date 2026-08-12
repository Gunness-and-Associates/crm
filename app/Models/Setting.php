<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * A single per-company configuration value. The `value` column holds a
 * JSON-encoded scalar or array — encrypted first when is_secret is true
 * (see {@see Settings}).
 *
 * @property string $key
 * @property string|null $value
 * @property bool $is_secret
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'value', 'is_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
