<p>Measured kilograms only. More than three decimals, exponents, estimates, negative quantities and inconsistent net/gross/tare are rejected. Do not invent a balancing output or loss.</p>
<input type="hidden" name="unit" value="kg">
<x-select-field name="measure_mode" label="Measurement mode" :options="$mode === 'output' ? ['unknown'=>'Unknown — accounting pending','net'=>'Measured net kg','gross_tare'=>'Measured gross minus tare kg'] : ['net'=>'Measured net kg','gross_tare'=>'Measured gross minus tare kg']" />
<x-field name="net_kg" label="Net kg (net-only mode; optional exact check in gross/tare mode)" :required="false" maxlength="13" />
<x-field name="gross_kg" label="Gross kg (gross/tare mode only)" :required="false" maxlength="13" />
<x-field name="tare_kg" label="Tare kg (gross/tare mode only)" :required="false" maxlength="13" />
<x-field name="measurement_source" label="Private measurement instrument/source and provenance" :required="false" maxlength="1000" hint="Required for measured mass. Leave all quantities and provenance blank when output mass is unknown." />
