<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class IntegrationController
{
    public function token(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|string|max:100',
            'client_secret' => 'required|string|size:64|regex:/^[a-f0-9]+$/',
        ]);
        [$plain, $minutes] = DB::transaction(function () use ($request, $data): array {
            $empresa = Empresa::where('api_client_id', $data['client_id'])->lockForUpdate()->first();
            abort_unless($empresa && $empresa->api_client_secret_hash && Hash::check($data['client_secret'], $empresa->api_client_secret_hash), 401);
            abort_unless($empresa->podeConsultar(), 403);
            $request->attributes->set('empresa', $empresa);
            $minutes = 15;
            $empresa->update(['api_ultimo_acesso' => now()]);
            $token = $empresa->createToken('integracao', ['integracao'], now()->addMinutes($minutes));
            Audit::write('empresas', $empresa->getKey(), 'EMITIR_TOKEN', $request);

            return [$token->plainTextToken, $minutes];
        });

        return response()->json(['access_token' => $plain, 'token_type' => 'Bearer', 'expires_in' => $minutes * 60]);
    }
}
