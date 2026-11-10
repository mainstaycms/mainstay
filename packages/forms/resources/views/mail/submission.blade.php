<h1>{{ $form->title }}</h1>
<dl>
    @foreach ($answers as $answer)
        <dt>{{ $answer['label'] }}</dt>
        <dd>{{ is_bool($answer['value']) ? ($answer['value'] ? 'Yes' : 'No') : $answer['value'] }}</dd>
    @endforeach
</dl>
