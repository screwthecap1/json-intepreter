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

    <!-- Форма фильтрации -->
    <div class="filters">
        <h2>Фильтрация связей</h2>
        <form action="{{ route('xml.filter') }}" method="POST">
            @csrf
            <label>Тип связи:</label>
            <select name="filter_type">
                <option value="all">Все</option>
                @foreach($relationshipTypes as $relType)
                    <option value="{{ $relType }}" {{ request('filter_type') == $relType ? 'selected' : '' }}>
                        {{ $relType }}
                    </option>
                @endforeach
            </select>

            <label>Поиск по терму:</label>
            <select name="term_filter">
                <option value="">Все термы</option>
                @foreach($terms as $term)
                    <option value="{{ $term }}" {{ request('term_filter') == $term ? 'selected' : '' }}>
                        {{ $term }}
                    </option>
                @endforeach
            </select>


            <button type="submit">Применить</button>
        </form>
    </div>

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

        <label>Новое имя (определение):</label>
        <input type="text" name="new_name" placeholder="Оставьте пустым, чтобы не менять"><br><br>

        <label>Тип узла:</label>
        <select name="node_type">
            <option value="action">Прямоугольник (Action)</option>
            <option value="decision">Ромб (Decision)</option>
        </select><br><br>

        <label>Тип связи:</label>
        <select name="relationship" required>
            @foreach($allRelations as $relation)
                <option value="{{ $relation }}">{{ $relation }}</option>
            @endforeach
        </select><br><br>

        <label>Назначение (связь с):</label>
        <select name="target" required>
            @foreach(array_diff($terms, ['Начало']) as $term)
                <option value="{{ $term }}">{{ $term }}</option>
            @endforeach
        </select><br><br>

        <button type="submit">Обновить связь</button>
    </form>

    <form action="{{ route('terms.reset') }}" method="POST" style="margin-top: 20px;">
        @csrf
        @method('DELETE')
        <button type="submit" onclick="return confirm('Вы уверены, что хотите очистить все определения и категории?');">
            Очистить все определения и категории
        </button>
    </form>

    <form action="{{ route('xml.export') }}" method="GET" style="margin-top: 30px;">
        <button type="submit">Скачать XML</button>
    </form>


    <!-- Таблица с результатами -->
    @if(isset($relationshipsGrouped) && $relationshipsGrouped->count())
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
            @foreach($relationshipsGrouped as $class => $group)
                @if($group->count())
                    <tr>
                        <td colspan="4" style="background: #ecf0f1; font-weight: bold; text-align: left;">
                            {{ $class }}
                        </td>
                    </tr>
                    @foreach($group as $rel)
                        <tr>
                            <td>{{ $rel->class1 }}</td>
                            <td>{{ $rel->relationship_type ?? '—' }}</td>
                            <td>{{ $rel->relationship }}</td>
                            <td>{{ $rel->class2 }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            </tbody>
        </table>
    @else
        <p style="text-align: center; margin-top: 20px;">Нет данных для отображения.</p>
    @endif
</div>

</body>
</html>
