<?php

namespace App\Domain;

final class Identifiers
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function pet(): string
    {
        $body = '';
        for ($position = 0; $position < 12; $position++) {
            $body .= self::ALPHABET[random_int(0, 31)];
        }

        return 'PET-'.$body.'-'.self::petCheck($body);
    }

    public static function normalizePet(string $value): ?string
    {
        $value = strtoupper(trim($value));
        if (! preg_match('/^PET-([0-9A-HJKMNP-TV-Z]{12})-([0-9]{2})$/D', $value, $parts)) {
            return null;
        }

        return $parts[2] === self::petCheck($parts[1]) ? $value : null;
    }

    private static function petCheck(string $body): string
    {
        $remainder = 0;
        foreach (str_split($body) as $character) {
            $remainder = ($remainder * 32 + strpos(self::ALPHABET, $character)) % 97;
        }

        return str_pad((string) (98 - ($remainder * 100) % 97), 2, '0', STR_PAD_LEFT);
    }

    public static function cpf(string $value): ?string
    {
        $value = str_replace(['.', '-', ' '], '', trim($value));
        if (! preg_match('/^[0-9]{11}$/D', $value) || preg_match('/^(\d)\1{10}$/D', $value)) {
            return null;
        }
        for ($length = 9; $length <= 10; $length++) {
            $sum = 0;
            for ($position = 0; $position < $length; $position++) {
                $sum += (int) $value[$position] * ($length + 1 - $position);
            }
            if ((string) (($sum * 10 % 11) % 10) !== $value[$length]) {
                return null;
            }
        }
        return $value;
    }

    public static function cnpj(string $value): ?string
    {
        $value = strtoupper(str_replace(['.', '/', '-', ' '], '', trim($value)));
        if (! preg_match('/^[A-Z0-9]{12}[0-9]{2}$/D', $value) || preg_match('/^(\d)\1{13}$/D', $value)) {
            return null;
        }
        for ($length = 12; $length <= 13; $length++) {
            $sum = 0;
            $weight = $length - 7;
            for ($position = 0; $position < $length; $position++) {
                $sum += (ord($value[$position]) - 48) * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }
            $digit = $sum % 11;
            if ((string) ($digit < 2 ? 0 : 11 - $digit) !== $value[$length]) {
                return null;
            }
        }
        return $value;
    }
}
