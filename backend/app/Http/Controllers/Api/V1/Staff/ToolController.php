<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Tool;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToolController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Tool::class);

        $query = Tool::query()->with('courses:id,key,code,name_ar');

        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }

        if ($term = trim($request->string('q')->toString())) {
            $query->where(
                fn ($inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
            );
        }

        if ($courseKey = $request->string('course')->toString()) {
            $query->whereHas(
                'courses',
                fn ($inner) => $inner->where('courses.key', $courseKey)
            );
        }

        $items = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(max(1, min($request->integer('per_page', 25), 100)));

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Tool::class);

        $data = $this->validated($request);

        $tool = Tool::create($data['tool']);

        $tool->courses()->sync($this->pivot($data['course_ids']));

        return response()->json([
            'message' => 'تمت إضافة الأداة.',
            'data' => $tool->load('courses:id,key,code,name_ar'),
        ], 201);
    }

    public function update(Request $request, Tool $tool)
    {
        $this->authorize('update', $tool);

        $data = $this->validated($request, $tool);

        $tool->update($data['tool']);

        /*
         * الربط يُزامَن فقط إذا أُرسل المفتاح. غيابه يعني «لا تلمس
         * المساقات» — بدون هذا الفرق يمسح تعديلُ اسمٍ كلَّ ارتباطات
         * الأداة، لأن sync([]) يفصلها عن الجميع.
         */
        if ($request->has('course_ids')) {
            $tool->courses()->sync($this->pivot($data['course_ids']));
        }

        return response()->json([
            'message' => 'تم تحديث الأداة.',
            'data' => $tool->fresh()->load('courses:id,key,code,name_ar'),
        ]);
    }

    public function destroy(Tool $tool)
    {
        $this->authorize('delete', $tool);

        $tool->delete();

        return response()->json(['message' => 'تم حذف الأداة.']);
    }

    /**
     * التحقّق، والشرط المتبادل الذي لا يُعبَّر عنه في الجدول:
     * المفهوم يحتاج شرحًا لأن زره يفتح نافذة، وما عداه يحتاج رابطًا
     * لأن زره يذهب إلى مكان. بدون هذا يُحفظ زرٌّ لا وجهة له.
     */
    private function validated(Request $request, ?Tool $tool = null): array
    {
        $editing = $tool !== null;
        $required = $editing ? 'sometimes' : 'required';

        $type = $request->input('type', $tool?->type);
        $isConcept = $type === 'concept';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:190'],
            'type' => [$required, Rule::in(Tool::TYPES)],
            'description' => [$required, 'string', 'max:300'],

            'official_url' => [
                $isConcept ? 'nullable' : $required,
                'nullable', 'url:http,https', 'max:500',
            ],

            'explanation' => [
                $isConcept ? $required : 'nullable',
                'nullable', 'string', 'max:5000',
            ],

            'video_url' => ['nullable', 'url:http,https', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'course_ids' => ['sometimes', 'array', 'max:100'],
            'course_ids.*' => ['integer', Rule::exists('courses', 'id')],
        ]);

        return [
            'tool' => collect($data)->except('course_ids')->all(),
            'course_ids' => $data['course_ids'] ?? [],
        ];
    }

    /** ترتيب الأدوات داخل المساق يتبع ترتيب الإرسال. */
    private function pivot(array $courseIds): array
    {
        return collect($courseIds)
            ->unique()
            ->values()
            ->mapWithKeys(
                fn ($id, $index) => [(int) $id => ['sort_order' => $index]]
            )
            ->all();
    }
}
