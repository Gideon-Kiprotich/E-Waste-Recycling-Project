@props(['fieldIds' => [], 'id' => 'form-errors'])
@if($errors->any())
    <x-feedback type="error" :id="$id" title="Please check the following" :focus="true">
        <ul>
            @foreach($errors->getMessages() as $field => $messages)
                @php($fieldId = $fieldIds[$field] ?? null)
                @foreach($messages as $error)<li>@if($fieldId)<a href="#{{ $fieldId }}">{{ $error }}</a>@else{{ $error }}@endif</li>@endforeach
            @endforeach
        </ul>
    </x-feedback>
@elseif(session('status'))
    <x-feedback :type="session('status_type', 'success')" :message="session('status')" />
@endif
