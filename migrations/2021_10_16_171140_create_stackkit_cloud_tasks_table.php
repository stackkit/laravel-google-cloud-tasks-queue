<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStackkitCloudTasksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stackkit_cloud_tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('queue');
            $table->string('task_uuid');
            $table->string('name');
            $table->string('status');
            $table->text('metadata');
            $table->text('payload');
            $table->timestamps();

            $table->index('task_uuid');
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
        Schema::dropIfExists('stackkit_cloud_tasks');
    }
}
