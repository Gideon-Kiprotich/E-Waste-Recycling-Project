<?php

namespace App\Support\Devices;

class Questions
{
    public const VERSION = 'SELF-REPORT-1.0';

    public const PARTS_VERSION = 'PARTS-SELF-REPORT-1.0';

    public const CATEGORIES = ['laptop' => 'Laptop', 'desktop' => 'Desktop', 'phone' => 'Mobile phone', 'flat_monitor' => 'Flat-panel monitor', 'printer' => 'Printer', 'peripheral' => 'Small ICT peripheral', 'standalone_part' => 'Standalone ICT part'];

    public const PART_SUBTYPES = ['hdd' => 'Hard disk drive (HDD)', 'ssd' => 'Solid-state drive (SSD)', 'ict_cooling_fan' => 'Small ICT cooling fan'];

    public const FAN_SCOPE_MESSAGE = 'This intake is limited to small ICT cooling fans up to 24 V DC and 140 mm. If you are unsure, do not test or dismantle the fan.';

    public const FAN_ELECTRONICS_MESSAGE = 'Fans with additional control, network or storage modules are outside this intake scope.';

    public const DECLARATIONS = ['YES' => 'Yes', 'NO' => 'No', 'UNKNOWN' => 'Unknown'];

    public const RESULTS = ['PASS' => 'Works / passed', 'FAIL' => 'Does not work / failed', 'UNKNOWN' => 'Unknown / not tested'];

    public const PAST_RESULTS = ['PASS' => 'Worked when previously used', 'FAIL' => 'Did not work when previously used', 'UNKNOWN' => 'Unknown / not previously observed'];

    public const FAN_ELECTRONICS = ['BASIC' => 'Basic integral fan circuitry', 'ADDITIONAL' => 'Additional control/network/storage module', 'UNKNOWN' => 'Unknown'];

    public const PART_TESTS = [
        'hdd' => ['previous_operation' => 'Previously able to store/read data', 'past_unusual_noise' => 'Unusual noise already noticed during past use', 'connector_damage' => 'Already visible connector damage'],
        'ssd' => ['previous_operation' => 'Previously able to store/read data', 'past_heat_issue' => 'Overheating already noticed during past use', 'connector_damage' => 'Already visible connector damage'],
        'ict_cooling_fan' => ['fan_scope' => 'Meets the small ICT cooling fan definition', 'fan_electronics' => 'Fan electronics already known', 'previous_rotation' => 'Rotation already observed during past use', 'past_unusual_noise' => 'Noise/vibration already noticed during past use'],
    ];

    public const TESTS = [
        'laptop' => ['power' => 'Power / self-test', 'display' => 'Usable display', 'input' => 'Keyboard / input', 'system' => 'Storage / system operation'],
        'desktop' => ['power' => 'Power / self-test', 'system' => 'System operation', 'display_input' => 'Display / input with an available test setup', 'storage' => 'Storage operation'],
        'phone' => ['power_system' => 'Power / system operation', 'display_touch' => 'Display / touch', 'charging' => 'Charging function'],
        'flat_monitor' => ['power' => 'Power', 'image' => 'Stable image with an available test source'],
        'printer' => ['power' => 'Power', 'test_page' => 'Test-page output', 'feed' => 'Paper feed / mechanical function'],
        'router' => ['power' => 'Power', 'connectivity' => 'Link / connectivity'],
        'keyboard' => ['input' => 'Key / input with a compatible host'],
    ];

    public static function tests(string $category, ?string $subtype, ?string $version = null): array
    {
        $version ??= self::version($category);
        if ($version === self::PARTS_VERSION && $category === 'standalone_part') {
            return self::PART_TESTS[$subtype ?? ''] ?? [];
        }
        if ($version !== self::VERSION) {
            throw new \UnexpectedValueException('Unsupported self-report schema version.');
        }

        return self::TESTS[$category === 'peripheral' ? ($subtype ?? '') : $category] ?? [];
    }

    public static function version(string $category): string
    {
        return $category === 'standalone_part' ? self::PARTS_VERSION : self::VERSION;
    }

    public static function subtypes(string $category): array
    {
        return match ($category) {
            'peripheral' => ['router' => 'Router', 'keyboard' => 'Keyboard'],
            'standalone_part' => self::PART_SUBTYPES,
            default => [],
        };
    }

    public static function options(string $code, string $version): array
    {
        if ($version === self::VERSION) {
            return self::RESULTS;
        }
        if ($version !== self::PARTS_VERSION) {
            throw new \UnexpectedValueException('Unsupported self-report schema version.');
        }

        return match ($code) {
            'previous_operation', 'previous_rotation' => self::PAST_RESULTS,
            'fan_electronics' => self::FAN_ELECTRONICS,
            default => self::DECLARATIONS,
        };
    }

    public static function dataStatus(string $category, ?string $subtype, array $fields): string
    {
        if ($category !== 'standalone_part') {
            return $fields['data_status'];
        }
        if (in_array($subtype, ['hdd', 'ssd'], true)) {
            return 'YES';
        }

        return ($fields['answers']['fan_scope'] ?? null) === 'YES' && ($fields['answers']['fan_electronics'] ?? null) === 'BASIC' ? 'NO' : 'UNKNOWN';
    }
}
