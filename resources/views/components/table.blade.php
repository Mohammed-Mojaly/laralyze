{{-- A scrolling table with a sticky header. Pass the header cells in the "head" slot. --}}
<div class="lz-table-wrap">
    <table {{ $attributes->class('lz-table') }}>
        <thead>
            <tr>{{ $head }}</tr>
        </thead>
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
