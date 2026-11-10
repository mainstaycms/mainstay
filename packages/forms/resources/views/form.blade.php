{{--
 | A form as a page prints it: its content in a <form> that posts to the
 | plugin's endpoint, or the confirmation in its place once it was accepted.
 | Unstyled, for the site's own stylesheet.
--}}
@if ($accepted)
    <div role="status">{!! \Mainstay\Forms\Http\Confirmation::message($form) !!}</div>
@else
    <form method="post" action="{{ route('mainstay.forms.submit', $form->id) }}" enctype="application/x-www-form-urlencoded">
        @csrf
        @if ($problems !== [])
            <ul role="alert">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        @endif
        {!! $content !!}
    </form>
@endif
