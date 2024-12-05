<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlugIn extends Model
{
    protected $table = 'plugins';
    protected $fillable = [
        'class',
        'version',
        'active',
        'migrate_status',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function scopeActive($builder)
    {
        return $builder->where('active', true)->where('migrate_status', 'success');
    }

    public function scopeMigrateSuccess($builder)
    {
        return $builder->where('migrate_status', 'success');
    }

    public function scopeMigratePending($builder)
    {
        return $builder->where('migrate_status', 'pending');
    }

    public function scopeMigrateFailed($builder)
    {
        return $builder->where('migrate_status', 'failed');
    }

    public function scopeMigrateRollback($builder)
    {
        return $builder->where('migrate_status', 'rollback');
    }
}
