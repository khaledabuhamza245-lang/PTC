<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Term;
use App\Models\User;
use App\Services\CurrentCoursesSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UserController extends Controller
{
    private const PRIMARY_ADMIN_EMAIL =
        'ptchub.duckdns.org@gmail.com';

    /**
     * حماية حساب المدير الرئيسي.
     */
    private function protectPrimaryAdmin(
        User $user
    ): void {
        $userEmail = strtolower(
            trim((string) $user->email)
        );

        $primaryEmail = strtolower(
            trim(self::PRIMARY_ADMIN_EMAIL)
        );

        if ($userEmail === $primaryEmail) {
            abort(
                403,
                'هذا هو حساب المدير الرئيسي ولا يمكن تعديله أو حذفه أو تغيير صلاحيته.'
            );
        }
    }

    public function index(Request $request)
    {
        $search = trim(
            (string) $request->query(
                'search',
                ''
            )
        );

        $role = trim(
            (string) $request->query(
                'role',
                ''
            )
        );

        $users = User::query()
            /*
             * المشرف يرى الطلاب — هذا جوهر عمله. لكن لا يرى حسابات
             * المدراء ولا المشرفين الآخرين: /staff/users و /admin/users
             * يشتركان في هذا المعالج، فبلا هذا التقييد كان المشرف يحصل
             * على دليل الموظفين كاملًا ببُردهم، وهي معرفة لا يحتاجها
             * وتفيد من يريد استهداف حساب مدير.
             *
             * التقييد هنا لا في العميل، ويُطبَّق قبل فلتر الدور القادم
             * من الطلب فلا يمكن تجاوزه بـ ?role=admin.
             */
            ->when(
                ! $request->user()->isAdmin(),
                fn ($query) => $query->where('role', 'student')
            )
            ->when(
                $search,
                function (
                    $query,
                    $search
                ) {
                    /*
                     * الاسم صار ثلاثة أعمدة، فبحث «أحمد عيسى» لا يطابق
                     * أي عمود منفرد. نقسّم النص لكلمات ونطلب من كل كلمة
                     * أن تطابق أحد الأعمدة — هكذا يبقى البحث بالاسم
                     * الكامل شغّالًا، والبحث بكلمة واحدة كما كان.
                     */
                    $terms = preg_split(
                        '/\s+/u',
                        $search,
                        -1,
                        PREG_SPLIT_NO_EMPTY
                    ) ?: [];

                    $query->where(
                        function (
                            $query
                        ) use ($terms) {
                            foreach ($terms as $term) {
                                $value =
                                    '%'.$term.'%';

                                $query->where(
                                    function (
                                        $query
                                    ) use ($value) {
                                        $query
                                            ->where(
                                                'first_name',
                                                'like',
                                                $value
                                            )
                                            ->orWhere(
                                                'father_name',
                                                'like',
                                                $value
                                            )
                                            ->orWhere(
                                                'last_name',
                                                'like',
                                                $value
                                            )
                                            ->orWhere(
                                                'email',
                                                'like',
                                                $value
                                            );
                                    }
                                );
                            }
                        }
                    );
                }
            )
            ->when(
                $role,
                fn (
                    $query,
                    $role
                ) =>
                    $query->where(
                        'role',
                        $role
                    )
            )
            ->latest()
            ->paginate(
                max(
                    1,
                    min(
                        $request->integer(
                            'per_page',
                            25
                        ),
                        100
                    )
                )
            );

        return response()->json(
            $users
        );
    }

    public function store(
        Request $request
    ) {
        $data = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:60',
            ],

            'father_name' => [
                'required',
                'string',
                'max:60',
            ],

            'last_name' => [
                'required',
                'string',
                'max:60',
            ],

            'email' => [
                'required',
                'email',
                'max:190',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8),
            ],

            'year' => [
                'nullable',
                'integer',
                'between:1,4',
            ],

            'semester' => [
                'nullable',
                'integer',
                'between:1,2',
            ],

            'role' => [
                'nullable',
                Rule::in([
                    'student',
                    'supervisor',
                ]),
            ],
        ]);

        $data['role'] =
            $data['role']
            ?? 'student';

        $user = User::create(
            $data + [
                'current_term_id' =>
                    Term::current()?->id,
            ]
        );

        app(CurrentCoursesSyncService::class)
            ->sync($user);

        return response()->json([
            'message' =>
                'تم إنشاء المستخدم بنجاح.',

            'data' => $user,
        ], 201);
    }

    public function update(
        Request $request,
        User $user
    ) {
        $this->protectPrimaryAdmin(
            $user
        );

        if (
            ! in_array(
                $user->role,
                [
                    'student',
                    'supervisor',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'يمكن تعديل حسابات الطلاب والمشرفين فقط من هذا القسم.',
            ], 422);
        }

        $data = $request->validate([
            'first_name' => [
                'required',
                'string',
                'max:60',
            ],

            'father_name' => [
                'required',
                'string',
                'max:60',
            ],

            'last_name' => [
                'required',
                'string',
                'max:60',
            ],

            'email' => [
                'required',
                'email',
                'max:190',

                Rule::unique(
                    'users',
                    'email'
                )->ignore(
                    $user->id
                ),
            ],

            'year' => [
                'nullable',
                'integer',
                'between:1,4',
            ],

            'semester' => [
                'nullable',
                'integer',
                'between:1,2',
            ],

            'password' => [
                'nullable',
                'confirmed',
                PasswordRule::min(8),
            ],
        ]);

        if (
            empty(
                $data['password']
            )
        ) {
            unset(
                $data['password']
            );
        }

        $user->update(
            $data
        );

        if (array_key_exists('year', $data) || array_key_exists('semester', $data)) {
            app(CurrentCoursesSyncService::class)
                ->sync($user);
        }

        /*
         * تصفير كلمة السر من اللوحة هو العلاج الوحيد الذي تعرضه الواجهة
         * لحساب مخترَق — فلو بقيت توكنات الضحية حيّة صار «الإصلاح» وهمًا:
         * سانكتم لا يربط التوكن بتجزئة كلمة السر، وعمره الافتراضي ١٤ يومًا
         * (config/sanctum.php)، فيظلّ المهاجم داخلًا أسبوعين بعد أن يطمئن
         * الأدمن والطالب معًا.
         *
         * وتغيير البريد مثله: إن صحّح الأدمن بريدًا اختُطف، فالجلسة القائمة
         * عليه يجب أن تسقط.
         *
         * وهذا ما تفعله كل المسارات الأخرى أصلًا — AuthController بعد
         * إعادة التعيين، وProfileController عند تغيير المستخدم كلمته،
         * وdestroy هنا. هذا المسار وحده كان يتخلّف عنها.
         */
        if (array_key_exists('password', $data) || $user->wasChanged('email')) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' =>
                'تم تحديث بيانات المستخدم.',

            'data' =>
                $user->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        User $user
    ) {
        $this->protectPrimaryAdmin(
            $user
        );

        if (
            $request
                ->user()
                ->is($user)
        ) {
            return response()->json([
                'message' =>
                    'لا يمكنك حذف حسابك الحالي.',
            ], 422);
        }

        if (
            ! in_array(
                $user->role,
                [
                    'student',
                    'supervisor',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'يمكن حذف حسابات الطلاب والمشرفين فقط.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' =>
                'تم حذف المستخدم بنجاح.',
        ]);
    }

    public function updateRole(
        Request $request,
        User $user
    ) {
        $this->protectPrimaryAdmin(
            $user
        );

        $data = $request->validate([
            'role' => [
                'required',

                Rule::in([
                    'student',
                    'supervisor',
                    'admin',
                ]),
            ],
        ]);

        if (
            $request
                ->user()
                ->is($user)
            &&
            $data['role']
                !== 'admin'
        ) {
            return response()->json([
                'message' =>
                    'لا يمكنك إزالة صلاحيتك الإدارية بنفسك.',
            ], 422);
        }

        $user->update([
            'role' =>
                $data['role'],
        ]);

        return response()->json([
            'message' =>
                'تم تحديث الصلاحية.',

            'data' =>
                $user->fresh(),
        ]);
    }

    public function updateRoleByEmail(
        Request $request
    ) {
        /*
         * أولًا التحقق من البيانات.
         */
        $data = $request->validate([
            'email' => [
                'required',
                'email',
                'exists:users,email',
            ],

            'role' => [
                'required',

                Rule::in([
                    'student',
                    'supervisor',
                    'admin',
                ]),
            ],
        ]);

        /*
         * بعدها جلب الحساب المطلوب.
         */
        $email = strtolower(
            trim(
                $data['email']
            )
        );

        $user = User::query()
            ->whereRaw(
                'LOWER(email) = ?',
                [$email]
            )
            ->firstOrFail();

        /*
         * حماية المدير الرئيسي فقط.
         */
        $this->protectPrimaryAdmin(
            $user
        );

        /*
         * منع الأدمن من تخفيض
         * صلاحية الحساب الذي دخل منه.
         */
        if (
            $request
                ->user()
                ->is($user)
            &&
            $data['role']
                !== 'admin'
        ) {
            return response()->json([
                'message' =>
                    'لا يمكنك إزالة صلاحيتك الإدارية بنفسك.',
            ], 422);
        }

        $user->update([
            'role' =>
                $data['role'],
        ]);

        return response()->json([
            'message' =>
                'تم تحديث الصلاحية.',

            'data' =>
                $user->fresh(),
        ]);
    }
}