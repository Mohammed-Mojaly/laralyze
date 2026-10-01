@props(['cols' => 'full', 'rows' => 1, 'class' => ''])
<section class="lz-card lz-span-{{ $cols }} lz-rows-{{ $rows }} lz-placeholder {{ $class }}" aria-busy="true">
    <div class="lz-skeleton lz-skeleton-title"></div>
    <div class="lz-skeleton lz-skeleton-body"></div>
</section>
