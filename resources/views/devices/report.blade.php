<dl class="facts">
    @foreach($tests as $code => $label)<dt>{{ $label }}</dt><dd>{{ \App\Support\Devices\Questions::RESULTS[$observation->answers['answers'][$code]] }}</dd>@endforeach
    <dt>Physical damage</dt><dd>{{ \App\Support\Devices\Questions::DECLARATIONS[$observation->answers['physical_damage']] }}</dd>
    <dt>Data-bearing</dt><dd>{{ \App\Support\Devices\Questions::DECLARATIONS[$observation->answers['data_status']] }}</dd>
    <dt>Safety concern</dt><dd>{{ \App\Support\Devices\Questions::DECLARATIONS[$observation->answers['safety_concern']] }}</dd>
    <dt>Observation notes</dt><dd>{{ $observation->answers['reason'] }}</dd>
</dl>
