<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassRelationship;
use SimpleXMLElement;
use Illuminate\Support\Str;

class XmlUploadController extends Controller
{
    public function index()
    {
        $relationships = ClassRelationship::all();

        // Шаг 1. Считаем позиции Y из diagram.xml
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

        // Шаг 2. Собираем и сортируем термы по координате Y
        $terms = $relationships->pluck('class1')
            ->merge($relationships->pluck('class2'))
            ->unique()
            ->filter(fn($term) => isset($positions[$term]))
            ->sortBy(fn($term) => $positions[$term])
            ->values()
            ->toArray();

        // Шаг 3. Поднимаем "Начало" вверх
        if (($key = array_search('Начало', $terms)) !== false) {
            unset($terms[$key]);
            array_unshift($terms, 'Начало');
        }

        // Шаг 4. Группируем связи по отсортированным термам
        $relationshipsGrouped = collect($terms)->mapWithKeys(function ($term) use ($relationships) {
            return [$term => $relationships->where('class1', $term)];
        });

        // Справочник типов связей
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

        return view('upload', compact('relationshipsGrouped', 'terms', 'relationshipTypes', 'allRelations'));
    }


    public function upload(Request $request)
    {
        $request->validate([
            'xml_file' => 'required|file',
        ]);

        // Сохраняем оригинал загруженного XML
        $original = file_get_contents($request->file('xml_file'));
        file_put_contents(storage_path('app/private/diagram.xml'), $original);

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
                // Сохраняем информацию о связях
                $edges[] = [
                    'source' => (string)$attr['source'],
                    'target' => (string)$attr['target'],
                    'label' => (string)($attr['value'] ?? '') // Для подписей стрелок
                ];
            }
        }

        // Обрабатываем связи
        foreach ($edges as $edge) {
            $source = $nodes[$edge['source']]['label'] ?? 'Unknown';
            $target = $nodes[$edge['target']]['label'] ?? 'Unknown';
            $sourceType = $nodes[$edge['source']]['type'] ?? null;

            // Для ромбиков используем подпись стрелки как тип связи
            $relationship = ($sourceType === 'decision' && !empty($edge['label']))
                ? $edge['label']
                : 'причина для';

            ClassRelationship::create([
                'class1' => $source,
                'relationship' => $relationship,
                'class2' => $target,
                'relationship_type' => $sourceType,
            ]);
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
            ->sort()
            ->values()
            ->toArray();

        if (($key = array_search('Начало', $terms)) !== false) {
            unset($terms[$key]);
            array_unshift($terms, 'Начало');
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
            'target' => 'required|string',
            'relationship' => 'required|string',
            'new_name' => 'nullable|string',
            'node_type' => 'nullable|in:action,decision' // Добавляем выбор типа узла
        ]);

        $oldName = $request->source;
        $newName = $request->new_name ?: $oldName;

        // 1. Переименование блока в БД
        if ($request->new_name) {
            ClassRelationship::where('class1', $oldName)->update(['class1' => $newName]);
            ClassRelationship::where('class2', $oldName)->update(['class2' => $newName]);
        }

        // 2. Удалить все старые связи ОТ источника
        ClassRelationship::where('class1', $newName)->delete();

        // 3. Добавить новую связь
        ClassRelationship::create([
            'class1' => $newName,
            'relationship' => $request->relationship,
            'class2' => $request->target,
            'relationship_type' => $request->node_type, // Сохраняем тип узла
        ]);

        // 4. Обновить XML файл
        $filePath = storage_path('app/private/diagram.xml');
        if (!file_exists($filePath)) {
            return redirect()->back()->with('error', 'XML файл не найден');
        }

        $xml = simplexml_load_file($filePath);
        if (!$xml || !isset($xml->diagram->mxGraphModel->root)) {
            return redirect()->back()->with('error', 'Некорректная структура XML');
        }

        $root = $xml->diagram->mxGraphModel->root;

        // 5. Сбор ID по названиям блоков
        $idMap = [];
        foreach ($root->mxCell as $cell) {
            if ((string)$cell['vertex'] === '1') {
                $label = (string)$cell['value'];
                $idMap[$label] = (string)$cell['id'];
            }
        }

        $sourceId = $idMap[$oldName] ?? null;
        $targetId = $idMap[$request->target] ?? null;

        if ($sourceId && $targetId) {
            // 6. Удалить старые исходящие стрелки от source
            foreach ($root->mxCell as $i => $cell) {
                if ((string)$cell['edge'] === '1' && (string)$cell['source'] === $sourceId) {
                    unset($root->mxCell[$i]);
                }
            }

            // 7. Переименовать блок и обновить тип
            foreach ($root->mxCell as $cell) {
                if ((string)$cell['vertex'] === '1' && (string)$cell['value'] === $oldName) {
                    $cell['value'] = $newName;

                    // Обновляем тип узла
                    $style = (string)$cell['style'];
                    if ($request->node_type === 'decision') {
                        $cell['style'] = str_replace('rounded=1', 'rhombus', $style);
                    } else {
                        $cell['style'] = str_replace('rhombus', 'rounded=1', $style);
                    }
                }
            }

            // 8. Добавить новую стрелку
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

            // 9. Сохранить XML
            file_put_contents($filePath, $xml->asXML());
        }

        return redirect()->back()->with('success', 'Связь обновлена корректно.');
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

        // Для обычных блоков - только последняя связь
        $usedSources = [];
        // Для ромбиков - все связи
        $decisionRelations = [];

        foreach ($relations as $rel) {
            if (in_array($idMap[$rel->class1] ?? null, $decisionBlocks)) {
                $decisionRelations[] = $rel;
            } else {
                $usedSources[$rel->class1] = $rel;
            }
        }

        // Добавляем связи для обычных блоков (только последнюю)
        foreach ($usedSources as $rel) {
            $this->addEdge($newRoot, $idMap, $rel, $edgeCounter++);
        }

        // Добавляем ВСЕ связи для ромбиков
        foreach ($decisionRelations as $rel) {
            $this->addEdge($newRoot, $idMap, $rel, $edgeCounter++);
        }

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
        $sourceId = $idMap[$relation->class1] ?? null;
        $targetId = $idMap[$relation->class2] ?? null;

        if ($sourceId && $targetId) {
            $edge = $root->addChild('mxCell');
            $edge->addAttribute('id', 'edge_' . $edgeId);
            $edge->addAttribute('edge', '1');
            $edge->addAttribute('source', $sourceId);
            $edge->addAttribute('target', $targetId);

            // Для ответвлений ромбика добавляем подписи
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
}
