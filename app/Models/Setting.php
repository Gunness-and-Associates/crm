<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * A single per-company configuration value. The `value` column holds a
 * JSON-encoded scalar or array (see {@see Settings}).
 *
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'value'];
}
