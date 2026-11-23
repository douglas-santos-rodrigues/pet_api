<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CatalogController
{
    public function species()
    {
        return response()->json(['data' => DB::table('especies')->where('ativo', true)->orderBy('nome')->get()]);
    }

    public function breeds(Request $request)
    {
        $filters = $request->validate(['especie_id' => ['required', 'integer', Rule::exists('especies', 'id_especie')->where('ativo', true)]]);

        return response()->json(['data' => DB::table('racas')->where('id_especie', $filters['especie_id'])->where('ativo', true)->orderBy('nome')->get()]);
    }

    public function procedures()
    {
        return response()->json(['data' => DB::table('tipos_procedimento')->where('ativo', true)->orderBy('nome')->get()]);
    }
}
