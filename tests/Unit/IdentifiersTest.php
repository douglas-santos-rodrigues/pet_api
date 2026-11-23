<?php

namespace Tests\Unit;

use App\Domain\Identifiers;
use PHPUnit\Framework\TestCase;

final class IdentifiersTest extends TestCase
{
    public function test_pet_code_normalizes_and_detects_an_invalid_check_digit(): void
    {
        $codes = [];
        for ($index = 0; $index < 200; $index++) {
            $code = Identifiers::pet();
            $codes[] = $code;
            self::assertSame($code, Identifiers::normalizePet(strtolower($code)));
            $invalid = substr($code, 0, -1).(((int) substr($code, -1) + 1) % 10);
            self::assertNull(Identifiers::normalizePet($invalid));
        }
        self::assertCount(200, array_unique($codes));
    }

    public function test_brazilian_document_formats(): void
    {
        self::assertSame('12345678909', Identifiers::cpf('123.456.789-09'));
        self::assertNull(Identifiers::cpf('111.111.111-11'));
        self::assertSame('12345678000195', Identifiers::cnpj('12.345.678/0001-95'));
        self::assertSame('12ABC34501DE35', Identifiers::cnpj('12.ABC.345/01DE-35'));
        self::assertNull(Identifiers::cnpj('12.ABC.345/01DE-36'));
    }
}
