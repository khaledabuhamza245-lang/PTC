<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Term;

class TermController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Term::ordered()->get(),
            'current' => Term::current(),
        ]);
    }
}
