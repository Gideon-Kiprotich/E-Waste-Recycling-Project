<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class NairobiTime
{
    public const ZONE = 'Africa/Nairobi';

    public const BASIS = 'africa_nairobi_v1';

    public static function localMinute(string $value): string
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $value)) {
            throw ValidationException::withMessages(['observed_at_local' => 'Enter a Nairobi date and time in YYYY-MM-DDTHH:MM format.']);
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone(self::ZONE));
        if (substr($value, 0, 4) === '0000' || ! $time || $time->format('Y-m-d\TH:i') !== $value) {
            throw ValidationException::withMessages(['observed_at_local' => 'Enter a real Nairobi date and time, to the minute.']);
        }

        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i');
    }

    public static function normalize(array $fields): array
    {
        if (! array_key_exists('time_basis', $fields) && ! array_key_exists('observed_at_local', $fields)) {
            return $fields;
        }
        if (($fields['time_basis'] ?? null) !== self::BASIS || ! is_string($fields['observed_at_local'] ?? null) || array_key_exists('observed_at', $fields)) {
            throw ValidationException::withMessages(['observed_at_local' => 'Use only the versioned Nairobi time field. Do not combine local and UTC fields.']);
        }
        $fields['observed_at'] = self::localMinute($fields['observed_at_local']);
        unset($fields['observed_at_local'], $fields['time_basis']);

        return $fields;
    }

    public static function display(DateTimeInterface|string|null $instant): string
    {
        return $instant === null ? 'Not recorded' : CarbonImmutable::parse($instant)->setTimezone(self::ZONE)->format('j M Y H:i').' EAT (Africa/Nairobi)';
    }
}
