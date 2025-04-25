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

        return view('upload', compact('relationships', 'terms', 'relationshipTypes'));
    }


        public function upload(Request $request)
    {
        $request->validate([
            'xml_file' => 'required|file',
        ]);

        $xml = new SimpleXMLElement(file_get_contents($request->file('xml_file')));
        $diagram = $xml->diagram;
        $cells = $diagram->mxGraphModel->root->children();

        ClassRelationship::truncate();

        $nodes = []; // [id => ['label' => ..., 'type' => ...]]

        // 1. Сохраняем все вершины
        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['vertex']) && $attr['vertex'] == '1') {
                $style = (string) $attr['style'];
                $type = 'action';
                if (str_contains($style, 'rhombus')) {
                    $type = 'decision';
                }

                $nodes[(string)$attr['id']] = [
                    'label' => (string)($attr['value'] ?? 'Unknown'),
                    'type' => $type
                ];
            }
        }

        // 2. Обрабатываем стрелки
        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['edge']) && $attr['edge'] == '1' && isset($attr['source']) && isset($attr['target'])) {
                $sourceId = (string)$attr['source'];
                $targetId = (string)$attr['target'];
                $label = (string)($attr['value'] ?? '');
                $source = $nodes[$sourceId]['label'] ?? 'Unknown';
                $target = $nodes[$targetId]['label'] ?? 'Unknown';

                ClassRelationship::create([
                    'class1' => $source,
                    'relationship' => $label ?: 'переход',
                    'class2' => $target,
                    'relationship_type' => $nodes[$sourceId]['type'] ?? null,
                ]);
            }
        }

        return redirect()->route('xml.index')->with('success', 'Activity Diagram успешно загружена.');
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

