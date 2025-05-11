<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClassRelationship;
use SimpleXMLElement;

class XmlUploadController extends Controller
{
    public function index()
    {
        $relationships = ClassRelationship::all();

        // Новый код: собираем все уникальные термы
        $terms = ClassRelationship::select('class1')
            ->union(ClassRelationship::select('class2'))
            ->distinct()
            ->orderBy('class1')
            ->pluck('class1')
            ->toArray();

        $relationshipTypes = ClassRelationship::select('relationship')
            ->distinct()
            ->orderBy('relationship')
            ->pluck('relationship')
            ->toArray();

        $definitionsByTerm = ClassRelationship::select('class1', 'definition', 'relationship_category')
            ->groupBy('class1', 'definition', 'relationship_category')
            ->get()
            ->keyBy('class1');

        return view('upload', compact('relationships', 'terms', 'relationshipTypes', 'definitionsByTerm'));
    }


    public function upload(Request $request)
    {
        $request->validate([
            'xml_file' => 'required|file',
        ]);

        $xml = new \SimpleXMLElement(file_get_contents($request->file('xml_file')));
        $diagram = $xml->diagram;
        $cells = $diagram->mxGraphModel->root->children();

        ClassRelationship::truncate();

        $nodes = []; // [id => ['label' => ..., 'type' => ...]]

        // 1. Сохраняем все узлы
        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['vertex']) && $attr['vertex'] == '1') {
                $style = (string)$attr['style'];
                $type = str_contains($style, 'rhombus') ? 'decision' : 'action';

                $nodes[(string)$attr['id']] = [
                    'label' => (string)($attr['value'] ?? 'Unknown'),
                    'type' => $type
                ];
            }
        }

        // 2. Добавляем 2 связи: причина → и следствие ←
        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['edge']) && $attr['edge'] == '1' && isset($attr['source']) && isset($attr['target'])) {
                $sourceId = (string)$attr['source'];
                $targetId = (string)$attr['target'];
                $source = $nodes[$sourceId]['label'] ?? 'Unknown';
                $target = $nodes[$targetId]['label'] ?? 'Unknown';

                // Прямая связь: A причина для B
                ClassRelationship::create([
                    'class1' => $source,
                    'relationship' => 'причина для',
                    'class2' => $target,
                    'relationship_type' => $nodes[$sourceId]['type'] ?? null,
                ]);

                // Обратная связь: B следствие для A
                ClassRelationship::create([
                    'class1' => $target,
                    'relationship' => 'следствие для',
                    'class2' => $source,
                    'relationship_type' => $nodes[$targetId]['type'] ?? null,
                ]);
            }
        }
        return redirect()->route('xml.index')->with('success', 'XML диаграмма успешно загружена.');
    }

    public function updateTerm(Request $request)
    {
        $term = $request->input('term');
        $definition = $request->input('definition');
        $category = $request->input('relationship_category');

        ClassRelationship::where('class1', $term)
            ->orWhere('class2', $term)
            ->update([
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

        $terms = ClassRelationship::select('class1')
            ->union(ClassRelationship::select('class2'))
            ->distinct()
            ->orderBy('class1')
            ->pluck('class1')
            ->toArray();

        $relationshipTypes = ClassRelationship::select('relationship')
            ->distinct()
            ->orderBy('relationship')
            ->pluck('relationship')
            ->toArray();

        return view('upload', compact('relationships', 'terms', 'relationshipTypes'));
    }
}

