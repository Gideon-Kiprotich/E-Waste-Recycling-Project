<input type="hidden" name="time_basis" value="africa_nairobi_v1">
<x-field name="observed_at_local" type="datetime-local" label="Actual observation time — Africa/Nairobi (EAT, UTC+03:00)" :value="now('Africa/Nairobi')->format('Y-m-d\TH:i')" hint="Enter the actual local observation time. Server recording time is separate. Explain retrospective entry in the private reason." />

@error('observed_at')<p class="field-error" role="alert">{{ $message }} UTC is the server's canonical time; enter the corresponding Nairobi local time above.</p>@enderror
