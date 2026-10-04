<?php

namespace App\Support\Devices;

class Questions
{
    public const VERSION = 'SELF-REPORT-1.0';

    public const CATEGORIES = ['laptop' => 'Laptop', 'desktop' => 'Desktop', 'phone' => 'Mobile phone', 'flat_monitor' => 'Flat-panel monitor', 'printer' => 'Printer', 'peripheral' => 'Small ICT peripheral'];

    public const DECLARATIONS = ['YES' => 'Yes', 'NO' => 'No', 'UNKNOWN' => 'Unknown'];

    public const RESULTS = ['PASS' => 'Works / passed', 'FAIL' => 'Does not work / failed', 'UNKNOWN' => 'Unknown / not tested'];

    public const TESTS = [
        'laptop' => ['power' => 'Power / self-test', 'display' => 'Usable display', 'input' => 'Keyboard / input', 'system' => 'Storage / system operation'],
        'desktop' => ['power' => 'Power / self-test', 'system' => 'System operation', 'display_input' => 'Display / input with an available test setup', 'storage' => 'Storage operation'],
        'phone' => ['power_system' => 'Power / system operation', 'display_touch' => 'Display / touch', 'charging' => 'Charging function'],
        'flat_monitor' => ['power' => 'Power', 'image' => 'Stable image with an available test source'],
        'printer' => ['power' => 'Power', 'test_page' => 'Test-page output', 'feed' => 'Paper feed / mechanical function'],
        'router' => ['power' => 'Power', 'connectivity' => 'Link / connectivity'],
        'keyboard' => ['input' => 'Key / input with a compatible host'],
    ];

    public static function tests(string $category, ?string $subtype): array
    {
        return self::TESTS[$category === 'peripheral' ? ($subtype ?? '') : $category] ?? [];
    }
}
