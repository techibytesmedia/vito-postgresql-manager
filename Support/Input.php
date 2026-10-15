<?php

namespace App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager\Support;

use Illuminate\Validation\ValidationException;

final class Input
{
    public static function name(string $name): string
    {
        if ($name === '' || strlen($name) > 63 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw ValidationException::withMessages(['name' => 'Use 1–63 UTF-8 bytes without control characters.']);
        }
        return $name;
    }
    public static function id(mixed $option): int
    {
        if (!is_string($option) || !preg_match('/^([1-9][0-9]*): /', $option, $m)) {
            throw ValidationException::withMessages(['token' => 'Choose an item from the current list. Refresh the Features page if needed.']);
        }
        return (int) $m[1];
    }
    public static function shell(string $value): string
    {
        if (str_contains($value, "\0")) { throw new \InvalidArgumentException('NUL in shell argument.'); }
        // POSIX escaping must remain POSIX when Vito's control host is Windows.
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }
    public static function verifier(#[\SensitiveParameter] string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 1024 || preg_match('/[^\x20-\x7e]/', $password)) {
            throw ValidationException::withMessages(['password' => 'Use 12–1024 printable ASCII characters. Symbols and spaces are supported.']);
        }
        $salt = random_bytes(16);
        $salted = hash_pbkdf2('sha256', $password, $salt, 4096, 32, true);
        $client = hash_hmac('sha256', 'Client Key', $salted, true);
        $server = hash_hmac('sha256', 'Server Key', $salted, true);
        return 'SCRAM-SHA-256$4096:'.base64_encode($salt).'$'.base64_encode(hash('sha256', $client, true)).':'.base64_encode($server);
    }
}
