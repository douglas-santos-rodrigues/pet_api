<?php

namespace App\Policies;

use App\Models\Pet;
use App\Models\Tutor;

final class PetPolicy
{
    public function view(Tutor $tutor, Pet $pet): bool
    {
        return $pet->id_tutor === $tutor->getKey();
    }
}
