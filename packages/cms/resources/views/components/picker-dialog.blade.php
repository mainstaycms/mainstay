{{--
 | The library's picker every image or file field on the page shares, Details' and a
 | block sidebar's, drawn once whichever asks first.
--}}
@once
    @push('dialogs')
        <x-mainstay::dialog id="mainstay-picker" label="Choose from the library" wide>
            <div data-picker-body aria-live="polite" class="max-h-[70vh] overflow-y-auto">
                <p class="text-sm text-muted">Loading the library…</p>
            </div>
        </x-mainstay::dialog>
    @endpush
@endonce
