<?php

namespace App\Support\Devices;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SafeInput
{
    public static function from(Request $request): array
    {
        $item = $request->route('item') ? Item::with('category')->where('owner_user_id', $request->user()?->id)->find($request->route('item')) : null;
        $category = $item?->category->code ?? (is_string($request->input('category')) ? $request->input('category') : '');
        $subtype = $item?->subtype ?? (is_string($request->input('subtype')) ? $request->input('subtype') : null);
        $safe = [];
        if (! $request->route('item')) {
            if (isset(Questions::CATEGORIES[$category])) {
                $safe['category'] = $category;
            }
            if (isset(Questions::subtypes($category)[$subtype ?? ''])) {
                $safe['subtype'] = $subtype;
            }
            foreach (['model' => 150, 'identifier_kind' => 6] as $field => $limit) {
                $value = $request->input($field);
                if (is_string($value) && mb_strlen($value) <= $limit && ($field !== 'identifier_kind' || in_array($value, $category === 'standalone_part' ? ['serial'] : ['serial', 'imei'], true))) {
                    $safe[$field] = $value;
                }
            }
        } elseif (filter_var($request->input('expected_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $safe['expected_version'] = $request->input('expected_version');
        }
        foreach (['physical_damage', 'safety_concern', ...($category === 'standalone_part' ? [] : ['data_status'])] as $field) {
            if (is_string($request->input($field)) && isset(Questions::DECLARATIONS[$request->input($field)])) {
                $safe[$field] = $request->input($field);
            }
        }
        $reason = $request->input('reason');
        if (is_string($reason) && mb_strlen($reason) <= 1000) {
            $safe['reason'] = $reason;
        }
        $key = $request->input('idempotency_key');
        if (is_string($key) && Str::isUuid($key)) {
            $safe['idempotency_key'] = $key;
        }
        foreach (Questions::tests($category, $subtype) as $code => $label) {
            $value = $request->input('answers.'.$code);
            if (is_string($value) && isset(Questions::options($code, Questions::version($category))[$value])) {
                $safe['answers'][$code] = $value;
            }
        }

        return $safe;
    }
}
