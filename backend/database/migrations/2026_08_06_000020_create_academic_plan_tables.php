<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأساس الأكاديمي — دفعة واحدة.
 *
 * ستّ ميزات في ملف واحد عمدًا. الاستضافة مشتركة بلا SSH ولا تراجع،
 * فكل تشغيل ترحيل لحظة خطر: إن انقطع في منتصفه بقيت القاعدة بين
 * حالتين. ملف واحد يجعلها لحظة واحدة بدل ستّ.
 *
 * وكل خطوة محروسة بـ hasTable/hasColumn في الاتجاهين: إعادة التشغيل
 * بعد انقطاع تكمل من حيث وقفت بدل أن تفشل على أول جدول موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * الفصول أولًا: my_courses.term_id و users.current_term_id
         * كلاهما مفتاح أجنبي عليها.
         */
        if (! Schema::hasTable('terms')) {
            Schema::create('terms', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('label', 100);
                $table->string('academic_year', 20);

                /* 1 أول · 2 ثانٍ · 3 صيفي — نفس ترقيم courses.semester. */
                $table->unsignedTinyInteger('semester');

                /*
                 * التواريخ اختيارية لأن التقويم الرسمي غير متاح، والفصل
                 * يبقى صالحًا للاستعمال بدونها. يملؤها الأدمن حين يعرفها.
                 */
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();

                /*
                 * يديره الأدمن يدويًا لا تلقائيًا: الاستضافة بلا cron
                 * مضمون، فحساب «الفصل الحالي» من التاريخ يعطي نتيجة
                 * تعتمد على تواريخ قد لا تكون مضبوطة أصلًا.
                 */
                $table->boolean('is_current')->default(false)->index();

                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['academic_year', 'semester']);
            });
        }

        Schema::table('courses', function (Blueprint $table) {
            /*
             * NULL = «غير محدّد» لا صفر. الفرق ليس تجميليًا: مساق
             * بساعات مجهولة يجب أن يظهر في عدّاد النقص لا أن يختفي
             * صامتًا داخل مجموع صحيح ظاهريًا.
             *
             * وunsignedTinyInteger كافٍ: القياس على الـ75 قيمة أثبت
             * أنها كلها أعداد صحيحة (قرار ٧٫٥)، فلا حاجة لـ decimal.
             */
            if (! Schema::hasColumn('courses', 'credit_hours')) {
                $table->unsignedTinyInteger('credit_hours')->nullable()->after('semester');
            }

            /*
             * required | elective | placeholder.
             *
             * يلغي حيلة semester=3 التي كانت تميّز الاختياري برقم فصل
             * وهمي، ويعزل خانات EEEX 35XX الخمس — وهي مواضع في المقام
             * لا مساقات، وأي حاسبة تخرّج تعدّها حقيقية تضيف ١٥ ساعة
             * وهمية إلى رصيد الطالب.
             */
            if (! Schema::hasColumn('courses', 'course_type')) {
                $table->string('course_type', 20)->default('required')->after('credit_hours')->index();
            }

            /* تُسلَّم فارغة: لا يوجد وصف مساق في أي مصدر متاح اليوم. */
            if (! Schema::hasColumn('courses', 'description')) {
                $table->text('description')->nullable()->after('course_type');
            }

            if (! Schema::hasColumn('courses', 'objectives')) {
                $table->text('objectives')->nullable()->after('description');
            }
        });

        /*
         * المتطلبات السابقة.
         *
         * الأعمدة الثلاثة (المفتاح القابل لـ NULL + الرمز النصّي + علم
         * المراجعة) ليست علاجًا لستّة مراجع معطوبة اليوم بل للحالة
         * الدائمة: مساق يُضاف بمتطلب لم يُنشأ بعد، أو رمز يتغيّر في
         * خطة قادمة. حذفها متى حُسمت الستّة يعني هجرة جديدة عند أول
         * تعديل في الخطة.
         */
        if (! Schema::hasTable('course_prerequisites')) {
            Schema::create('course_prerequisites', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();

                $table->foreignId('prerequisite_course_id')
                    ->nullable()
                    ->constrained('courses')
                    ->nullOnDelete();

                /* الرمز كما ورد في المصدر — يُعرض للطالب نصًّا حين لا يُربط. */
                $table->string('prerequisite_code_raw', 50)->nullable();

                $table->boolean('needs_review')->default(false)->index();
                $table->timestamps();

                /*
                 * ⚠ القيد يحرس المربوطة وحدها: MySQL يعدّ كل NULL
                 * مميّزًا عن غيره فلا يمنع تكرار الصفوف النصّية. تكرارها
                 * يُمنع في البذور بـ firstOrCreate على الرمز لا بالقيد.
                 */
                $table->unique(['course_id', 'prerequisite_course_id']);
            });
        }

        /*
         * مواضيع المراجعة قبل المساق — ٣٥٢ صفًّا انتقلت من prep-guide.js.
         *
         * عمود link يُفعّل ميزة مكتوبة سلفًا في study-tools.html ولم
         * تُستعمل قط لأن المصدر الثابت لا يحمل روابط.
         */
        if (! Schema::hasTable('course_prep_topics')) {
            Schema::create('course_prep_topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->string('topic', 200);
                $table->string('link', 500)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['course_id', 'sort_order']);
            });
        }

        Schema::table('my_courses', function (Blueprint $table) {
            if (! Schema::hasColumn('my_courses', 'term_id')) {
                $table->foreignId('term_id')
                    ->nullable()
                    ->after('course_id')
                    ->constrained('terms')
                    ->nullOnDelete();
            }

            /*
             * registered | completed | dropped.
             *
             * هذا **مصدر الحقيقة الوحيد** لـ«أنجزتُ هذا المساق» (قرار
             * ٧٫١): إقرار ذاتي من الطالب. لا يُشتقّ من نسبة المحتوى —
             * مساق بلا محتوى نسبته ٠/٠ فلا يكتمل أبدًا.
             */
            if (! Schema::hasColumn('my_courses', 'status')) {
                $table->string('status', 20)->default('registered')->after('term_id')->index();
            }

            /* ترتيب الاختياريات في السقف يعتمد عليه، فهو ليس حقلًا زخرفيًا. */
            if (! Schema::hasColumn('my_courses', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('my_courses', 'grade')) {
                $table->string('grade', 5)->nullable()->after('completed_at');
            }
        });

        if (! Schema::hasColumn('users', 'current_term_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('current_term_id')
                    ->nullable()
                    ->after('year')
                    ->constrained('terms')
                    ->nullOnDelete();
            });
        }

        /*
         * إعدادات البرنامج.
         *
         * is_public ضروري لا تجميلي: /v1/program مسار عام يقرأ العام
         * وحده، فأي مفتاح إداري يُضاف لاحقًا لا يتسرّب بمجرّد إضافته.
         */
        if (! Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('value', 500)->nullable();
                $table->string('group', 40)->default('general')->index();
                $table->boolean('is_public')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'current_term_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('current_term_id');
            });
        }

        Schema::table('my_courses', function (Blueprint $table) {
            if (Schema::hasColumn('my_courses', 'grade')) {
                $table->dropColumn('grade');
            }

            if (Schema::hasColumn('my_courses', 'completed_at')) {
                $table->dropColumn('completed_at');
            }

            if (Schema::hasColumn('my_courses', 'status')) {
                $table->dropColumn('status');
            }

            if (Schema::hasColumn('my_courses', 'term_id')) {
                $table->dropConstrainedForeignId('term_id');
            }
        });

        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('course_prep_topics');
        Schema::dropIfExists('course_prerequisites');

        Schema::table('courses', function (Blueprint $table) {
            foreach (['objectives', 'description', 'course_type', 'credit_hours'] as $column) {
                if (Schema::hasColumn('courses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('terms');
    }
};
