<x-admin.data-table :columns="['Hub-Feld', 'Änderung', 'Vorher (Immoware-Feld | Transform)', 'Nachher (Immoware-Feld | Transform)']" :rows="$diff" empty="Keine Regeln.">
    @foreach ($diff as $row)
        <tr data-diff-state="{{ $row['state'] }}">
            <td><code class="hub-mono">{{ $row['target_field'] }}</code></td>
            <td><x-admin.status-badge :status="match ($row['state']) { 'neu' => 'ok', 'geändert' => 'warn', 'entfernt' => 'fail', default => 'disabled' }" :label="$row['state']" /></td>
            <td>@if ($row['old'])<code class="hub-mono">{{ $row['old']['source_field'] }}</code> | {{ $row['old']['transform'] ?? 'keine' }}@endif</td>
            <td>@if ($row['new'])<code class="hub-mono">{{ $row['new']['source_field'] }}</code> | {{ $row['new']['transform'] ?? 'keine' }}@endif</td>
        </tr>
    @endforeach
</x-admin.data-table>
