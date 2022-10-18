<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue\Entities;

class StatRow
{
    public $count;
    public $failed;
    public $time_preset;

    public static function createFromObject(object $row): StatRow
    {
        $object = new self();

        foreach (get_object_vars($row) as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }
}
