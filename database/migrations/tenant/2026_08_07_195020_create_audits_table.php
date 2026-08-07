<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Published from owen-it/laravel-auditing, relocated to tenant/ and adjusted for this
// project's char(36) UUID primary keys (the stock migration assumes integer PKs on both
// the "user" morph and the "auditable" morph, which would corrupt data on save here).
class CreateAuditsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $morphPrefix = config('audit.user.morph_prefix', 'user');

            $table->bigIncrements('id');
            $table->string($morphPrefix.'_type')->nullable();
            $table->uuid($morphPrefix.'_id')->nullable();
            $table->string('event');
            $table->uuidMorphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();

            $table->index([$morphPrefix.'_id', $morphPrefix.'_type']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $table = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->drop($table);
    }
}
