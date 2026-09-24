<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TermController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Term::ordered()->get()]);
    }

    public function store(Request $request)
    {
        $term = Term::create($this->validated($request));

        $this->applyCurrent($term);

        return response()->json([
            'message' => 'تمت إضافة الفصل.',
            'data' => $term->fresh(),
        ], 201);
    }

    public function update(Request $request, Term $term)
    {
        $term->update($this->validated($request, $term));

        $this->applyCurrent($term);

        return response()->json([
            'message' => 'تم تحديث الفصل.',
            'data' => $term->fresh(),
        ]);
    }

    public function destroy(Term $term)
    {
        /*
         * الحذف مسموح لأن المفاتيح الأجنبية nullOnDelete: تسجيلات
         * الطلاب تفقد فصلها ولا تُمحى. لكن الفصل الحالي يُستثنى — حذفه
         * يترك النظام بلا فصل جارٍ بلا أن ينتبه أحد.
         */
        if ($term->is_current) {
            return response()->json([
                'message' => 'لا يُحذف الفصل الحالي — عيّن فصلًا حاليًا آخر أولًا.',
            ], 422);
        }

        $term->delete();

        return response()->json(['message' => 'تم حذف الفصل.']);
    }

    private function validated(Request $request, ?Term $term = null): array
    {
        $partial = $term !== null;

        return $request->validate([
            'code' => [
                $partial ? 'sometimes' : 'required',
                'string',
                'max:30',
                Rule::unique('terms', 'code')->ignore($term?->id),
            ],
            'label' => [$partial ? 'sometimes' : 'required', 'string', 'max:100'],
            'academic_year' => [$partial ? 'sometimes' : 'required', 'string', 'max:20'],
            'semester' => [$partial ? 'sometimes' : 'required', 'integer', 'between:1,3'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'is_current' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    /**
     * فصل حالي واحد لا أكثر.
     *
     * التصفير داخل transaction مع الضبط: لو تمّ التصفير ثم فشل الضبط
     * لبقي النظام بلا فصل حالٍ إطلاقًا، وهو أسوأ من فصلين.
     */
    private function applyCurrent(Term $term): void
    {
        if (! $term->fresh()->is_current) {
            return;
        }

        DB::transaction(function () use ($term) {
            Term::where('id', '!=', $term->id)->update(['is_current' => false]);
            $term->forceFill(['is_current' => true])->save();
        });
    }
}
