{!! $form->title !!}

@foreach ($answers as $answer)
{!! $answer['label'] !!}: {!! is_bool($answer['value']) ? ($answer['value'] ? 'Yes' : 'No') : $answer['value'] !!}
@endforeach
