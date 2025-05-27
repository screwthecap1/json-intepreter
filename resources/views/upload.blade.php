<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Загрузка XML | Онтология</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f2f4f8;
            padding: 30px;
            color: #333;
        }

        h1, h2 {
            text-align: center;
            color: #2c3e50;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }

        form {
            margin-bottom: 20px;
        }

        input[type="file"],
        select,
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin-top: 8px;
            margin-bottom: 16px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        button {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: #2980b9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
        }

        th {
            background-color: #3498db;
            color: white;
            padding: 12px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: center;
        }

        .success {
            color: green;
            text-align: center;
            margin-bottom: 15px;
        }

        .filters {
            margin-top: 20px;
        }

        @media (max-width: 600px) {
            th, td {
                font-size: 14px;
                padding: 8px;
            }
        }
    </style>
</head>
<body>
@php use Illuminate\Support\Str; @endphp
<div class="container">
    <h1>Загрузка XML-файла</h1>

    @if(session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif

    <!-- Форма загрузки XML -->
    <form action="{{ route('xml.upload') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <label for="xml_file">Выберите XML-файл:</label>
        <input type="file" name="xml_file" required>
        <button type="submit">Загрузить</button>
    </form>

    <!-- Измененная форма редактирования связей -->
    <h2>Редактировать связи</h2>
    <form action="{{ route('xml.relationship.update') }}" method="POST">
        @csrf

        <label>Источник (старое имя блока):</label>
        <select name="source" required>
            @foreach($terms as $term)
                <option value="{{ $term }}" {{ $term === 'Начало' ? 'selected' : '' }}>{{ $term }}</option>
            @endforeach
        </select><br><br>

        <label>Новое имя термина:</label>
        <input type="text" name="rename_term" placeholder="Новое имя термина" value="{{ old('rename_term') }}">

        <label>Описание терма:</label>
        <input type="text" name="definition" placeholder="Описание терма (обновится при сохранении)">

        <label>Тип узла:</label>
        <select name="node_type">
            <option value="">-- не выбрано --</option>
            <option value="action">Прямоугольник (Action)</option>
            <option value="decision">Ромб (Decision)</option>
        </select><br><br>

        <label>Тип связи:</label>
        <select name="relationship">
            <option value="">-- не выбрано --</option>
            @foreach($allRelations as $relation)
                <option value="{{ $relation }}">{{ $relation }}</option>
            @endforeach
        </select><br><br>

        <label>Назначение (связь с):</label>
        <select name="target">
            <option value="">-- не выбрано --</option>
            @foreach(array_diff($terms, ['Начало']) as $term)
                <option value="{{ $term }}">{{ $term }}</option>
            @endforeach
        </select><br><br>

        <button type="submit">Обновить связь</button>
    </form>

    <form action="{{ route('reset.all') }}" method="POST" style="margin-top: 10px;">
        @csrf
        <button type="submit" onclick="return confirm('Восстановить изначальную диаграмму и связи?')">Сбросить всё</button>
    </form>


    <form action="{{ route('xml.export') }}" method="GET" style="margin-top: 30px;">
        <button type="submit">Скачать XML</button>
    </form>


    <!-- Таблица с результатами -->
    @if(isset($relationshipsGrouped) && $relationshipsGrouped->count())
        <table>
            <thead>
            <tr>
                <th>Термин</th>
                <th>Описание</th>
            </tr>
            </thead>
            <tbody>
            @foreach($terms as $term)
                <tr>
                    <td><a href="{{ route('term.show', ['term' => str_replace('?', '__qm__', $term)]) }}">{{ $term }}</a></td>
                    <td>{{ $definitions[$term] ?: 'Нет описания' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p style="text-align: center; margin-top: 20px;">Нет данных для отображения.</p>
    @endif
</div>

</body>
</html>
