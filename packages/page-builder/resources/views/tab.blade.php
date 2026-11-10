{{-- The Content tab's panel. The block settings dialog is pushed out of the
     entry's form: see block-sidebar. --}}
<x-page_builder::canvas :url="$canvas" :block="$block" sidebar="mainstay-block" header="canvas-header" :blocks="$blocks" :top="$top" :invalid="$invalid" :pictures="$pictures" :previews="$previews" />
<x-page_builder::block-sidebar id="mainstay-block" />
