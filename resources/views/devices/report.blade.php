@php($reportTests = \App\Support\Devices\Questions::tests($item->category->code, $item->subtype, $observation->question_version))
<p class="hint">Question set: {{ $observation->question_version }}</p>
<dl class="facts">
    @foreach($reportTests as $code => $label)<dt>{{ $label }}</dt><dd>{{ \App\Support\Devices\Questions::options($code, $observation->question_version)[$observation->answers['answers'][$code]] }}</dd>@endforeach
    <dt>Physical damage</dt><dd>{{ \App\Support\Devices\Questions::DECLARATIONS[$observation->answers['physical_damage']] }}</dd>
    <dt>Data-bearing</dt><dd>{{ \App\Support\Devices\Questions::DECLARATIONS[$observation->answers['data_status']] }}{{ $item->isPart() && $item->subtype === 'ict_cooling_fan' ? ' — consumer self-report' : '' }}</dd>
    <dt>Safety concern</dt><dd>{{ \App\Support\Devices\Questions::DECLARATIONS[$observation->answers['safety_concern']] }}</dd>
    <dt>Observation notes</dt><dd>{{ $observation->answers['reason'] }}</dd>
</dl>
