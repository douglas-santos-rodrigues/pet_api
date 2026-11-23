<?php

namespace App\Policies;

use App\Models\Empresa;
use App\Models\Registro;
use App\Models\Tutor;

final class RegistroPolicy
{
    public function update(Tutor|Empresa $actor, Registro $registro): bool
    {
        if ($actor instanceof Empresa) {
            return $registro->origem === 'EMPRESA' && $registro->id_empresa_origem === $actor->getKey();
        }

        return $registro->origem === 'TUTOR'
            && $registro->id_tutor_origem === $actor->getKey()
            && $registro->pet?->id_tutor === $actor->getKey();
    }
}
