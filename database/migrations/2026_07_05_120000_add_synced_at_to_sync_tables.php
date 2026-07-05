<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These are the tables SyncController pushes to timesys-v2. Each needs
     * synced_at so pushModule() can filter to whereNull('synced_at') instead
     * of resending everything on every push.
     */
    private array $tables = [
        'employees',
        'offices',
        'office_divisions',
        'work_schedules',
        'attendances',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'synced_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('synced_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasColumn($table, 'synced_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('synced_at');
            });
        }
    }
};
