<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassRelationship;
use SimpleXMLElement;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;

class XmlUploadController extends Controller
{
    public function index()
    {
        $relationships = ClassRelationship::all();

        // Дефолтные определения
        $defaultDefinitions = [
            'Начало' => 'Начальная точка процесса.',
            'Ввод логина и пароля' => 'Этап, на котором пользователь вводит свои данные.',
            'Данные корректны?' => 'Проверка правильности введенных данных.',
            'Переход в профиль' => 'Переход в личный кабинет пользователя.',
            'Ошибка авторизации' => 'Сообщение об ошибке при неверном вводе.',
            'Конец' => 'Завершение процесса.'
        ];

        // Обновляем определения только если они пустые
        foreach ($defaultDefinitions as $term => $definition) {
            ClassRelationship::where('class1', $term)
                ->where(function ($q) {
                    $q->whereNull('definition')
                        ->orWhere('definition', '');
                })
                ->update(['definition' => $definition]);
        }

        // --- СЧИТЫВАЕМ ПОЗИЦИИ Y ---
        $filePath = storage_path('app/private/diagram.xml');
        $positions = [];

        if (file_exists($filePath)) {
            $xml = simplexml_load_file($filePath);
            $cells = $xml->diagram->mxGraphModel->root->mxCell ?? [];

            foreach ($cells as $cell) {
                $attr = $cell->attributes();
                if (isset($attr['vertex']) && $attr['vertex'] == '1') {
                    $label = (string)($attr['value'] ?? 'Unknown');
                    $geometry = $cell->mxGeometry;
                    $y = isset($geometry['y']) ? floatval($geometry['y']) : 0;
                    $positions[$label] = $y;
                }
            }
        }

        // 🔥 Сначала берём все термы из XML (из value у mxCell)
        $xmlTerms = [];

        if (file_exists($filePath)) {
            $xml = simplexml_load_file($filePath);
            foreach ($xml->diagram->mxGraphModel->root->mxCell as $cell) {
                if ((string)$cell['vertex'] === '1') {
                    $xmlTerms[] = (string)$cell['value'];
                }
            }
        }

// 🔄 Добавим термы из базы, если вдруг чего-то нет
        $terms = collect($xmlTerms)
            ->merge($relationships->pluck('class1'))
            ->merge($relationships->pluck('class2'))
            ->unique()
            ->values()
            ->sortBy(function ($term) use ($positions) {
                return $positions[$term] ?? INF;
            })
            ->values()
            ->toArray();


        // --- ПЕРЕНОСИМ "Начало" и "Конец" ---
        if (($startKey = array_search('Начало', $terms)) !== false) {
            unset($terms[$startKey]);
            array_unshift($terms, 'Начало');
        }

        if (($endKey = array_search('Конец', $terms)) !== false) {
            unset($terms[$endKey]);
            $terms[] = 'Конец';
        }

        // --- ГРУППИРУЕМ СВЯЗИ ---
        $relationshipsGrouped = collect($terms)->mapWithKeys(function ($term) use ($relationships) {
            return [$term => $relationships->filter(function ($rel) use ($term) {
                return $rel->class1 === $term || $rel->class2 === $term;
            })];
        });

        // --- ДОСТАЁМ ОПРЕДЕЛЕНИЯ ---
        // --- ДОСТАЁМ ОПРЕДЕЛЕНИЯ ---
        $definitions = [];
        foreach ($terms as $term) {
            $definition = ClassRelationship::where('class1', $term)
                ->whereNotNull('definition')
                ->where('definition', '!=', '')
                ->value('definition');

            $definitions[$term] = $definition ?? ($defaultDefinitions[$term] ?? '');
        }

        // --- ТИПЫ СВЯЗЕЙ ---
        $relationshipTypes = ClassRelationship::select('relationship')
            ->distinct()
            ->orderBy('relationship')
            ->pluck('relationship')
            ->toArray();

        $allRelations = [
            'Целое для', 'Часть от',
            'Родитель для', 'Наследник от',
            'Тип для', 'Реализация для',
            'Имеет атрибут', 'Атрибут для',
            'Причина для', 'Следствие для',
            'Сходство', 'Смежны', 'Контраст',
            'По времени (раньше-позже-одновременно)',
            'По пространству',
            'Синонимы', 'Участвует', 'Выполняет',
            'Инструмент для', 'Использует'
        ];

        return view('upload', compact(
            'relationshipsGrouped',
            'terms',
            'relationshipTypes',
            'allRelations',
            'definitions'
        ));
    }


    public function upload(Request $request)
    {
        $request->validate([
            'xml_file' => 'required|file',
        ]);

        // Сохраняем оригинал загруженного XML
        $original = file_get_contents($request->file('xml_file'));
        file_put_contents(storage_path('app/private/diagram.xml'), $original);
        file_put_contents(storage_path('app/private/diagram_original.xml'), $original);

        $xml = new \SimpleXMLElement($original);
        $diagram = $xml->diagram;
        $cells = $diagram->mxGraphModel->root->children();

        ClassRelationship::truncate();

        $nodes = [];
        $edges = [];

        // Сначала собираем все узлы
        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['vertex']) && $attr['vertex'] == '1') {
                $style = (string)$attr['style'];
                $type = str_contains($style, 'rhombus') ? 'decision' : 'action';

                $nodes[(string)$attr['id']] = [
                    'label' => (string)($attr['value'] ?? 'Unknown'),
                    'type' => $type,
                    'style' => $style
                ];
            } elseif (isset($attr['edge']) && $attr['edge'] == '1') {
                $edges[] = [
                    'source' => (string)$attr['source'],
                    'target' => (string)$attr['target'],
                    'label' => (string)($attr['value'] ?? '')
                ];
            }
        }

        // Обрабатываем связи
        foreach ($edges as $edge) {
            $source = $nodes[$edge['source']]['label'] ?? 'Unknown';
            $target = $nodes[$edge['target']]['label'] ?? 'Unknown';
            $sourceType = $nodes[$edge['source']]['type'] ?? null;

            $relationship = ($sourceType === 'decision' && !empty($edge['label']))
                ? $edge['label']
                : 'причина для';

            // Добавляем прямую связь
            ClassRelationship::create([
                'class1' => $source,
                'relationship' => $relationship,
                'class2' => $target,
                'relationship_type' => $sourceType,
            ]);

            // Добавляем обратную связь "следствие для", если нужно и ещё не существует
            if ($relationship === 'причина для' &&
                !ClassRelationship::where('class1', $target)
                    ->where('class2', $source)
                    ->where('relationship', 'следствие для')
                    ->exists()) {
                ClassRelationship::create([
                    'class1' => $target,
                    'relationship' => 'следствие для',
                    'class2' => $source,
                    'relationship_type' => $sourceType,
                ]);
            }
        }

        // Удаляем старые связи между Начало и Ввод логина и пароля
        // Удаляем ТОЛЬКО временные связи между Начало и Ввод логина и пароля
        ClassRelationship::where('class1', 'Начало')
            ->where('class2', 'Ввод логина и пароля')
            ->where('relationship', 'по времени (позже)')
            ->delete();

        ClassRelationship::where('class1', 'Ввод логина и пароля')
            ->where('class2', 'Начало')
            ->where('relationship', 'по времени (раньше)')
            ->delete();


        // Добавляем временные связи
        ClassRelationship::create([
            'class1' => 'Начало',
            'relationship' => 'по времени (позже)',
            'class2' => 'Ввод логина и пароля',
            'relationship_type' => 'action'
        ]);

        ClassRelationship::create([
            'class1' => 'Ввод логина и пароля',
            'relationship' => 'по времени (раньше)',
            'class2' => 'Начало',
            'relationship_type' => 'action'
        ]);

        // --- ОПРЕДЕЛЕНИЯ ПО УМОЛЧАНИЮ ---
        $defaultDefinitions = [
            'Начало' => 'Начальная точка процесса.',
            'Ввод логина и пароля' => 'Этап, на котором пользователь вводит свои данные.',
            'Данные корректны?' => 'Проверка правильности введенных данных.',
            'Переход в профиль' => 'Переход в личный кабинет пользователя.',
            'Ошибка авторизации' => 'Сообщение об ошибке при неверном вводе.',
            'Конец' => 'Завершение процесса.'
        ];

        // Обновляем определения только для стандартных терминов
        foreach ($defaultDefinitions as $term => $definition) {
            ClassRelationship::where('class1', $term)
                ->where(function ($q) {
                    $q->whereNull('definition')->orWhere('definition', '');
                })
                ->update(['definition' => $definition]);
        }

        return redirect()->route('xml.index')->with('success', 'XML диаграмма успешно загружена.');
    }

    public function updateTerm(Request $request)
    {
        $originalTerm = $request->input('term');
        $safeTerm = Str::slug($originalTerm, '_');

        $definition = $request->input("definition_$safeTerm");
        $category = $request->input("relationship_category_$safeTerm");

        ClassRelationship::where('class1', $originalTerm)->update([
            'definition' => $definition,
            'relationship_category' => $category,
        ]);

        return redirect()->back()->with('success', 'Определение и категория обновлены.');
    }


    public function filter(Request $request)
    {
        $positions = [];
        $type = $request->input('filter_type');
        $term = $request->input('term_filter');

        $query = ClassRelationship::query();

        if ($type && $type !== 'all') {
            $query->where('relationship', $type);
        }

        if ($term) {
            $query->where(function ($q) use ($term) {
                $q->where('class1', $term)
                    ->orWhere('class2', $term);
            });
        }

        $relationships = $query->get();

        $terms = $relationships->pluck('class1')
            ->merge($relationships->pluck('class2'))
            ->unique()
            ->values()
            ->sortBy(function ($term) use ($positions) {
                return $positions[$term] ?? INF; // INF — чтобы элементы без позиции шли в конец
            })
            ->values()
            ->toArray();

        // Переместим "Начало" в начало, а "Конец" — в конец
        if (($startKey = array_search('Начало', $terms)) !== false) {
            unset($terms[$startKey]);
            array_unshift($terms, 'Начало');
        }

        if (($endKey = array_search('Конец', $terms)) !== false) {
            unset($terms[$endKey]);
            $terms[] = 'Конец';
        }

        $relationshipsGrouped = collect($terms)->mapWithKeys(function ($term) use ($relationships) {
            return [$term => $relationships->where('class1', $term)];
        });


        $relationshipTypes = ClassRelationship::select('relationship')
            ->distinct()
            ->orderBy('relationship')
            ->pluck('relationship')
            ->toArray();

        return view('upload', compact('relationshipsGrouped', 'terms', 'relationshipTypes'));
    }

    public function customUpdateTerm(Request $request)
    {
        $request->validate([
            'term' => 'required|string',
            'definition' => 'nullable|string',
            'relationship_category' => 'nullable|string',
        ]);

        ClassRelationship::where('class1', $request->term)->update([
            'definition' => $request->definition,
            'relationship_category' => $request->relationship_category,
        ]);

        return redirect()->back()->with('success', 'Обновлено!');
    }

    public function resetDefinitions()
    {
        ClassRelationship::query()->update([
            'definition' => null,
            'relationship_category' => null,
        ]);

        return redirect()->back()->with('success', 'Все определения и категории были очищены.');
    }

    public function updateRelationship(Request $request)
    {
        $request->validate([
            'source' => 'required|string',
            'target' => 'nullable|string',
            'relationship' => 'nullable|string',
            'node_type' => 'nullable|in:action,decision',
            'definition' => 'nullable|string',
            'rename_term' => 'nullable|string'
        ]);

        $oldName = $request->source;
        $newName = $request->rename_term ?: $oldName;

        // Если меняется только описание
        if ($request->filled('definition') &&
            !$request->filled('rename_term') &&
            !$request->filled('node_type') &&
            !$request->filled('relationship') &&
            !$request->filled('target')) {

            ClassRelationship::where('class1', $oldName)
                ->update(['definition' => $request->definition]);
            return redirect()->route('xml.index')->with('success', 'Описание обновлено.');
        }

        // Обновляем XML
        $filePath = storage_path('app/private/diagram.xml');
        if (!file_exists($filePath)) {
            return redirect()->back()->with('error', 'XML файл не найден');
        }

        $xml = simplexml_load_file($filePath);
        if (!$xml || !isset($xml->diagram->mxGraphModel->root)) {
            return redirect()->back()->with('error', 'Некорректная структура XML');
        }

        $root = $xml->diagram->mxGraphModel->root;

        // Если меняется имя термина
        if ($request->filled('rename_term') && $request->rename_term !== $oldName) {
            // Обновляем в базе данных
            ClassRelationship::where('class1', $oldName)->update(['class1' => $request->rename_term]);
            ClassRelationship::where('class2', $oldName)->update(['class2' => $request->rename_term]);

            // Обновляем XML
            foreach ($root->mxCell as $cell) {
                if ((string)$cell['vertex'] === '1' && (string)$cell['value'] === $oldName) {
                    $cell['value'] = $request->rename_term;
                }
            }

            $newName = $request->rename_term;
        }

        // Обновляем описание, если оно передано
        if ($request->filled('definition')) {
            ClassRelationship::where('class1', $newName)
                ->update(['definition' => $request->definition]);
        }

        // Если указана цель связи, обновляем связи
        if ($request->filled('target')) {
            // Удаляем старые связи
            ClassRelationship::where('class1', $newName)
                ->where('class2', '!=', $request->target)
                ->delete();

            // Создаем/обновляем новую связь
            ClassRelationship::updateOrCreate([
                'class1' => $newName,
                'class2' => $request->target,
            ], [
                'relationship' => $request->relationship,
                'relationship_type' => $request->node_type,
            ]);

            // Обновляем стрелки в XML
            $idMap = [];
            foreach ($root->mxCell as $cell) {
                if ((string)$cell['vertex'] === '1') {
                    $label = (string)$cell['value'];
                    $idMap[$label] = (string)$cell['id'];
                }
            }

            $sourceId = $idMap[$newName] ?? null;
            $targetId = $idMap[$request->target] ?? null;

            if ($sourceId && $targetId) {
                // Удаляем старые стрелки
                foreach ($root->mxCell as $i => $cell) {
                    if ((string)$cell['edge'] === '1' &&
                        (string)$cell['source'] === $sourceId) {
                        unset($root->mxCell[$i]);
                    }
                }

                // Добавляем новую стрелку
                $edge = $root->addChild('mxCell');
                $edge->addAttribute('id', 'edge_' . uniqid());
                $edge->addAttribute('edge', '1');
                $edge->addAttribute('source', $sourceId);
                $edge->addAttribute('target', $targetId);
                $edge->addAttribute('style', 'endArrow=block;html=1;');
                $edge->addAttribute('parent', '1');

                $geometry = $edge->addChild('mxGeometry');
                $geometry->addAttribute('relative', '1');
                $geometry->addAttribute('as', 'geometry');
            }
        }

        // Обновляем тип узла, если указан
        if ($request->filled('node_type')) {
            foreach ($root->mxCell as $cell) {
                if ((string)$cell['vertex'] === '1' && (string)$cell['value'] === $newName) {
                    $style = (string)$cell['style'];
                    if ($request->node_type === 'decision') {
                        $cell['style'] = str_replace('rounded=1', 'rhombus', $style);
                    } else {
                        $cell['style'] = str_replace('rhombus', 'rounded=1', $style);
                    }
                }
            }
        }

        file_put_contents($filePath, $xml->asXML());

        return redirect()->route('xml.index')->with('success', 'Изменения сохранены.');
    }

    public function export()
    {
        $filePath = storage_path('app/private/diagram.xml');
        if (!file_exists($filePath)) {
            return back()->with('error', 'Файл diagram.xml не найден.');
        }

        $originalXml = simplexml_load_file($filePath);
        if (!$originalXml || !isset($originalXml->diagram->mxGraphModel->root)) {
            return back()->with('error', 'Некорректная структура XML.');
        }

        // Создаем новый XML с правильной структурой
        $xmlString = '<?xml version="1.0"?>
        <mxfile>
            <diagram id="0" name="RebuiltDiagram">
                <mxGraphModel dx="800" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="850" pageHeight="1100">
                    <root>
                        <mxCell id="0"/>
                        <mxCell id="1" parent="0"/>
                    </root>
                </mxGraphModel>
            </diagram>
        </mxfile>';

        $newXml = new \SimpleXMLElement($xmlString);
        $newRoot = $newXml->diagram->mxGraphModel->root;

        // Собираем информацию о блоках
        $blocks = [];
        foreach ($originalXml->diagram->mxGraphModel->root->mxCell as $cell) {
            if ((string)$cell['vertex'] === '1') {
                $blocks[(string)$cell['id']] = [
                    'value' => (string)$cell['value'],
                    'style' => (string)$cell['style'],
                    'geometry' => $cell->mxGeometry
                ];
            }
        }

        // Копируем все вершины (блоки)
        $idMap = [];
        $decisionBlocks = []; // Для хранения ID ромбиков
        foreach ($blocks as $id => $block) {
            $newCell = $newRoot->addChild('mxCell');
            $newCell->addAttribute('id', $id);
            $newCell->addAttribute('value', $block['value']);
            $newCell->addAttribute('style', $block['style']);
            $newCell->addAttribute('vertex', '1');
            $newCell->addAttribute('parent', '1');

            if ($block['geometry']) {
                $newGeometry = $newCell->addChild('mxGeometry');
                foreach ($block['geometry']->attributes() as $name => $value) {
                    $newGeometry->addAttribute($name, (string)$value);
                }
            }

            $idMap[$block['value']] = $id;

            // Запоминаем ромбики
            if (str_contains($block['style'], 'rhombus')) {
                $decisionBlocks[] = $id;
            }
        }

        // Добавляем связи из базы данных
        $relations = ClassRelationship::all();
        $edgeCounter = 1000;

        $normalRelations = [];
        $decisionRelations = [];

        foreach ($relations as $rel) {
            $sourceId = $idMap[$rel->class1] ?? null;
            if (!$sourceId) continue;

            if (in_array($sourceId, $decisionBlocks)) {
                $decisionRelations[] = $rel;
            } else {
                // Храним только последнюю связь для обычных блоков
                $normalRelations[$rel->class1][] = $rel;
            }
        }

        // Добавляем только ОДНУ связь от обычных блоков
        // Добавляем ВСЕ связи — и от обычных, и от ромбов
        foreach ($normalRelations as $rels) {
            foreach ($rels as $rel) {
                $this->addEdge($newRoot, $idMap, $rel, $edgeCounter++);
            }
        }
        foreach ($decisionRelations as $rel) {
            $this->addEdge($newRoot, $idMap, $rel, $edgeCounter++);
        }


//        // Для обычных блоков - только последняя связь
//        $usedSources = [];
//        // Для ромбиков - все связи
//        $decisionRelations = [];
//
//        foreach ($relations as $rel) {
//            if (in_array($idMap[$rel->class1] ?? null, $decisionBlocks)) {
//                $decisionRelations[] = $rel;
//            } else {
//                $usedSources[$rel->class1] = $rel;
//            }
//        }

//        // Добавляем связи для обычных блоков (только последнюю)
//        foreach ($usedSources as $rel) {
//            $this->addEdge($newRoot, $idMap, $rel, $edgeCounter++);
//        }

        // Форматируем вывод
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($newXml->asXML());

        return response($dom->saveXML(), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="diagram_rebuilt.xml"',
        ]);
    }

// Вспомогательная функция для добавления стрелки
    private function addEdge($root, $idMap, $relation, $edgeId)
    {
        // Пропустить только обратные связи
        if (in_array($relation->relationship, ['следствие для', 'по времени (раньше)'])) {
            return;
        }

        $sourceId = $idMap[$relation->class1] ?? null;
        $targetId = $idMap[$relation->class2] ?? null;

        if (!$sourceId || !$targetId) {
            return;
        }

        // Не дублируем, если стрелка уже есть
        foreach ($root->mxCell as $cell) {
            if ((string)$cell['edge'] === '1' &&
                (string)$cell['source'] === $sourceId &&
                (string)$cell['target'] === $targetId) {
                return;
            }
        }

        // Добавляем стрелку
        $edge = $root->addChild('mxCell');
        $edge->addAttribute('id', 'edge_' . $edgeId);
        $edge->addAttribute('edge', '1');
        $edge->addAttribute('source', $sourceId);
        $edge->addAttribute('target', $targetId);

        if (in_array($sourceId, $this->getDecisionBlockIds($root))) {
            $edge->addAttribute('style', 'endArrow=block;html=1;label=' . $relation->relationship);
        } else {
            $edge->addAttribute('style', 'endArrow=block;html=1;');
        }

        $edge->addAttribute('parent', '1');
        $geometry = $edge->addChild('mxGeometry');
        $geometry->addAttribute('relative', '1');
        $geometry->addAttribute('as', 'geometry');
    }



// Вспомогательная функция для получения ID ромбиков
    private function getDecisionBlockIds($root)
    {
        $ids = [];
        foreach ($root->mxCell as $cell) {
            if ((string)$cell['vertex'] === '1' && str_contains((string)$cell['style'], 'rhombus')) {
                $ids[] = (string)$cell['id'];
            }
        }
        return $ids;
    }

    public function showTerm($term)
    {
        $term = str_replace('__qm__', '?', $term);

        $exists = ClassRelationship::where('class1', $term)
            ->orWhere('class2', $term)
            ->exists();

        if (!$exists) {
            abort(404, 'Терм не найден.');
        }

        $outgoing = ClassRelationship::where('class1', $term)->get();

        $incoming = ClassRelationship::where('class2', $term)
            ->whereIn('relationship', ['следствие для', 'по времени (раньше)', 'по времени (позже)'])
            ->get();

// Добавляем принудительно входящую связь от "Данные корректны?", если нужно
        $extra = collect();
        if (in_array($term, ['Переход в профиль', 'Ошибка авторизации'])) {
            $extra = ClassRelationship::where('class1', 'Данные корректны?')
                ->where('class2', $term)
                ->where('relationship', 'следствие для')
                ->get();
        }

        $incoming = $incoming->merge($extra);

        // Удаляем входящие дубликаты уже показанных исходящих
        $filteredIncoming = $incoming->reject(function ($incomingRel) use ($outgoing) {
            return $outgoing->contains(function ($out) use ($incomingRel) {
                return $out->class2 === $incomingRel->class1 &&
                    $out->relationship === $this->inverseRelation($incomingRel->relationship);
            });
        });

        $relationships = $outgoing->merge($filteredIncoming)->values();

        $definition = ClassRelationship::where('class1', $term)
            ->whereNotNull('definition')
            ->value('definition') ?? 'Описание отсутствует.';

        $terms = ClassRelationship::pluck('class1')
            ->merge(ClassRelationship::pluck('class2'))
            ->unique()
            ->values()
            ->toArray();

        if (($startKey = array_search('Начало', $terms)) !== false) {
            unset($terms[$startKey]);
            array_unshift($terms, 'Начало');
        }
        if (($endKey = array_search('Конец', $terms)) !== false) {
            unset($terms[$endKey]);
            $terms[] = 'Конец';
        }

        $index = array_search($term, $terms);
        $prevTerm = $terms[$index - 1] ?? null;
        $nextTerm = $terms[$index + 1] ?? null;

        return view('term', compact('term', 'definition', 'relationships', 'prevTerm', 'nextTerm'));
    }

// 💡 Вспомогательная функция для получения обратного типа связи
    private function inverseRelation($rel)
    {
        return match ($rel) {
            'следствие для' => 'причина для',
            'причина для' => 'следствие для',
            'по времени (раньше)' => 'по времени (позже)',
            'по времени (позже)' => 'по времени (раньше)',
            default => $rel,
        };
    }



    public function resetAll()
    {
        // Путь к файлу
        $originalPath = storage_path('app/private/diagram_original.xml');
        $diagramPath = storage_path('app/private/diagram.xml');

        if (!file_exists($originalPath)) {
            return back()->with('error', 'Оригинальный XML не найден.');
        }

        // Перезапись текущего файла
        copy($originalPath, $diagramPath);

        // Очистка таблицы связей
        ClassRelationship::truncate();

        // Загрузка XML
        $xml = simplexml_load_file($diagramPath);
        $cells = $xml->diagram->mxGraphModel->root->children();

        $nodes = [];
        $edges = [];

        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['vertex']) && $attr['vertex'] == '1') {
                $style = (string)$attr['style'];
                $type = str_contains($style, 'rhombus') ? 'decision' : 'action';

                $nodes[(string)$attr['id']] = [
                    'label' => (string)($attr['value'] ?? 'Unknown'),
                    'type' => $type,
                ];
            } elseif (isset($attr['edge']) && $attr['edge'] == '1') {
                $edges[] = [
                    'source' => (string)$attr['source'],
                    'target' => (string)$attr['target'],
                    'label' => (string)($attr['value'] ?? '')
                ];
            }
        }

        // Добавление связей
        foreach ($edges as $edge) {
            $source = $nodes[$edge['source']]['label'] ?? 'Unknown';
            $target = $nodes[$edge['target']]['label'] ?? 'Unknown';
            $sourceType = $nodes[$edge['source']]['type'] ?? null;

            $relationship = ($sourceType === 'decision' && !empty($edge['label']))
                ? $edge['label']
                : 'причина для';

            ClassRelationship::create([
                'class1' => $source,
                'relationship' => $relationship,
                'class2' => $target,
                'relationship_type' => $sourceType,
            ]);

            if ($relationship === 'причина для') {
                ClassRelationship::create([
                    'class1' => $target,
                    'relationship' => 'следствие для',
                    'class2' => $source,
                    'relationship_type' => $sourceType,
                ]);
            }
        }
        return redirect()->route('xml.index')->with('success', 'Данные и схема восстановлены из оригинального XML.');
    }
}
