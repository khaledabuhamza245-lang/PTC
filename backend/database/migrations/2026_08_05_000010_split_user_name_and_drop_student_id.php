<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 60)->nullable()->after('id');
            $table->string('father_name', 60)->nullable()->after('first_name');
            $table->string('last_name', 60)->nullable()->after('father_name');
        });

        if (Schema::hasColumn('users', 'full_name')) {
            DB::table('users')
                ->select('id', 'full_name')
                ->chunkById(200, function ($users) {
                    foreach ($users as $user) {
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update($this->splitName((string) $user->full_name));
                    }
                });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('full_name');
            });
        }

        if (Schema::hasColumn('users', 'student_id')) {
            // لا بدّ من إسقاط الفهرس الفريد قبل العمود: MySQL و SQLite يرفضان حذف عمود مفهرس.
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique('users_student_id_unique');
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('student_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name', 120)->nullable()->after('id');
            $table->string('student_id', 50)->nullable()->unique()->after('full_name');
        });

        DB::table('users')
            ->select('id', 'first_name', 'father_name', 'last_name')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $parts = array_filter([
                        $user->first_name,
                        $user->father_name,
                        $user->last_name,
                    ], fn ($part) => trim((string) $part) !== '');

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['full_name' => implode(' ', $parts)]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'father_name', 'last_name']);
        });
    }

    /**
     * أول كلمة اسم أول، الثانية اسم أب، والباقي اسم عائلة.
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'first_name' => $parts[0] ?? '',
            'father_name' => $parts[1] ?? null,
            'last_name' => count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null,
        ];
    }
};
