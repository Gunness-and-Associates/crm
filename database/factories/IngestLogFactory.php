<?php

namespace Database\Factories;

use App\Models\IngestLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestLog>
 */
class IngestLogFactory extends Factory
{
    protected $model = IngestLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'wordpress',
            'raw_payload' => ['example' => 'payload'],
            'status' => 'received',
        ];
    }
}
