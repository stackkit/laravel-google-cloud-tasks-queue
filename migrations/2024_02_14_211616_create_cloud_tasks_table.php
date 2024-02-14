<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCloudTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('stackkit_cloud_tasks');

        Schema::create('cloud_tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('queue');
            $table->string('task_name');
            $table->string('name');
            $table->string('status');
            $table->timestamps();

            $table->index('task_name');
            $table->index('queue');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('cloud_tasks');
    }
}
