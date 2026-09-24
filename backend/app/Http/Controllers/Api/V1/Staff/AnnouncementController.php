<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Announcement::class);

        $items = Announcement::query()
            ->with(['course:id,key,code,name_ar'])
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 25), 100)));

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Announcement::class);

        $data = $this->validated($request);

        $item = Announcement::create(
            $this->normalized($data) + ['created_by' => $request->user()->id],
        );

        return response()->json([
            'message' => 'تم نشر الإعلان.',
            'data' => $item->load('course:id,key,code,name_ar'),
        ], 201);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->authorize('update', $announcement);

        $data = $this->validated($request, true);

        /*
         * التطبيع يحتاج الوجهة النهائية لا المرسَل وحده: طلبٌ يغيّر
         * `audience` إلى all بلا ذكر `course_id` يجب أن يمسح المساق
         * القديم، وطلبٌ لا يذكر `audience` أصلًا يجب أن يحتكم إلى
         * المخزَّن. الدمج قبل التطبيع هو ما يجعل الحالتين صحيحتين معًا.
         */
        $merged = array_merge(
            [
                'audience' => $announcement->audience,
                'audience_year' => $announcement->audience_year,
                'audience_semester' => $announcement->audience_semester,
                'course_id' => $announcement->course_id,
            ],
            $data,
        );

        $announcement->update($this->normalized($merged));

        return response()->json([
            'message' => 'تم تحديث الإعلان.',
            'data' => $announcement->fresh()->load('course:id,key,code,name_ar'),
        ]);
    }

    public function destroy(Announcement $announcement)
    {
        $this->authorize('delete', $announcement);

        $announcement->delete();

        return response()->json(['message' => 'تم حذف الإعلان.']);
    }

    /*
     * «ذكّر مرة ثانية»: لا إعلان جديد، بل نفس الإعلان يصير «جديدًا»
     * من جديد لكل من رآه سابقًا — نظام الإشعارات يعتمد created_at
     * وحده ليقرر ما هو جديد، فتحديثه يكفي بلا نسخ أو تكرار صفوف.
     */
    public function remind(Announcement $announcement)
    {
        $this->authorize('update', $announcement);

        $announcement->increment('reminder_count');
        $announcement->touch('created_at');

        return response()->json([
            'message' => 'تم تذكير الطلاب بالإعلان من جديد.',
            'data' => $announcement->fresh()->load('course:id,key,code,name_ar'),
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:190'],
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'active' => ['sometimes', 'boolean'],

            'audience' => ['sometimes', Rule::in(['all', 'year', 'year_semester', 'course'])],

            /*
             * required_if يحرس التناقض عند المصدر: «لسنة معيّنة» بلا سنة
             * كان سيُحفظ ويُرشَّح على audience_year = NULL فلا يصل أحدًا —
             * إعلانٌ يبدو منشورًا ولا يراه إنسان. year_semester يحتاج
             * كلا الحقلين معًا لنفس السبب بالضبط.
             */
            'audience_year' => [
                'nullable',
                'integer',
                'between:1,4',
                'required_if:audience,year',
                'required_if:audience,year_semester',
            ],

            'audience_semester' => [
                'nullable',
                'integer',
                'between:1,2',
                'required_if:audience,year_semester',
            ],

            'course_id' => [
                'nullable',
                'integer',
                'exists:courses,id',
                'required_if:audience,course',
            ],
        ]);
    }

    /**
     * تصفير الحقول التي لا تخصّ الوجهة المختارة.
     *
     * بدونه يبقى `course_id` قديمٌ معلّقًا على إعلان صار «لكل الطلاب»،
     * فيمرّ اليوم بلا أثر لأن الترشيح يفحص `audience` أولًا — ويصير
     * قنبلة موقوتة لأي استعلام لاحق يبدأ من `course_id`. البيانات
     * المتناقضة تُمنع عند الكتابة لا يُحترَس منها في كل قراءة.
     */
    private function normalized(array $data): array
    {
        $audience = $data['audience'] ?? 'all';

        $data['audience'] = $audience;
        $data['audience_year'] = in_array($audience, ['year', 'year_semester'], true)
            ? ($data['audience_year'] ?? null)
            : null;
        $data['audience_semester'] = $audience === 'year_semester' ? ($data['audience_semester'] ?? null) : null;
        $data['course_id'] = $audience === 'course' ? ($data['course_id'] ?? null) : null;

        return $data;
    }
}
