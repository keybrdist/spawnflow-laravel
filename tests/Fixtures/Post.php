<?php

namespace Spawnflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spawnflow\Concerns\HasSpawnflow;

class Post extends Model
{
    use HasSpawnflow;

    protected $fillable = ['title', 'body', 'status', 'owner_id'];
}
