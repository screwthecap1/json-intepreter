<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Связи: {{ $term }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            padding: 30px;
        }

        .container {
            max-width: 850px;
            margin: auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }

        h1 {
            text-align: center;
            color: #2c3e50;
        }

        .definition {
            font-style: italic;
            color: #555;
            text-align: center;
            margin-top: 10px;
            margin-bottom: 20px;
        }

        a {
            color: #3498db;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px 10px;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }

        th {
            background-color: #3498db;
            color: white;
        }

        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
        }

        .nav-buttons a {
            background-color: #3498db;
            color: white;
            padding: 10px 18px;
            border-radius: 6px;
            font-weight: bold;
            transition: background-color 0.2s ease;
        }

        .nav-buttons a:hover {
            background-color: #2980b9;
        }

        .back {
            display: inline-block;
            margin-bottom: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">
    <a href="{{ route('xml.index') }}" class="back">&larr; Назад к списку</a>
    <h1>Связи для терма: "{{ $term }}"</h1>

    <p class="definition">Определение: {{ $definition }}</p>

    @if($relationships->count())
        <table>
            <thead>
            <tr>
                <th>Терм 1</th>
                <th>Тип узла</th>
                <th>Связь</th>
                <th>Терм 2</th>
            </tr>
            </thead>
            <tbody>
            @foreach($relationships as $rel)
                <tr>
                    <td>
                        <a href="{{ route('term.show', ['term' => str_replace('?', '__qm__', $rel->class1)]) }}">
                            {{ $rel->class1 }}
                        </a>
                    </td>
                    <td>{{ $rel->relationship_type ?? '—' }}</td>
                    <td>{{ $rel->relationship }}</td>
                    <td>
                        <a href="{{ route('term.show', ['term' => str_replace('?', '__qm__', $rel->class2)]) }}">
                            {{ $rel->class2 }}
                        </a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p>Связей не найдено.</p>
    @endif

{{--    <div class="nav-buttons">--}}
{{--        @if($prevTerm)--}}
{{--            <a href="{{ route('term.show', ['term' => str_replace('?', '__qm__', $prevTerm)]) }}">&larr; {{ $prevTerm }}</a>--}}
{{--        @else--}}
{{--            <span></span>--}}
{{--        @endif--}}

{{--        @if($nextTerm)--}}
{{--            <a href="{{ route('term.show', ['term' => str_replace('?', '__qm__', $nextTerm)]) }}">{{ $nextTerm }} &rarr;</a>--}}
{{--        @endif--}}
{{--    </div>--}}
</div>
</body>
</html>
