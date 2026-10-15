@props(['config', 'height' => 'h-56'])
<div class="{{ $height }}"><canvas data-chart="{{ json_encode($config) }}"></canvas></div>
