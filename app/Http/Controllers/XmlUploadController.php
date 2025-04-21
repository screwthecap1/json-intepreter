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

        // Передаём обе переменные в Blade
        return view('upload', compact('relationships', 'terms'));
    }


    public function upload(Request $request)
    {
        logger('⏳ upload() стартует');

        $request->validate([
            'xml_file' => 'required|file',
        ]);
        logger('✅ Файл прошёл валидацию');

        $xml = new SimpleXMLElement(file_get_contents($request->file('xml_file')));

        // Прямой доступ к XML — draw.io сохранил <diagram> с полноценным деревом
        $cells = $xml->diagram->mxGraphModel->root->children();
        logger('📊 Найдено ячеек: ' . count($cells));

        ClassRelationship::truncate();
        logger('🧹 Очистка таблицы');

        $classes = [];

        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['value'])) {
                $id = (string)$attr['id'];
                $value = (string)$attr['value'];
                $classes[$id] = $value;
                logger("🧠 Добавлен терм: $id = $value");
            }
        }

        foreach ($cells as $cell) {
            $attr = $cell->attributes();
            if (isset($attr['source']) && isset($attr['target'])) {
                $source = $classes[(string)$attr['source']] ?? 'Unknown';
                $target = $classes[(string)$attr['target']] ?? 'Unknown';

                ClassRelationship::create([
                    'class1' => $source,
                    'relationship' => 'контролирует',
                    'class2' => $target,
                ]);

                ClassRelationship::create([
                    'class1' => $target,
                    'relationship' => 'контролируется',
                    'class2' => $source,
                ]);

                logger("🔗 Связь: $source → $target");
            }
        }

        logger('✅ upload() завершён, редиректим обратно');

        return redirect()->route('xml.index')->with('success', 'Файл успешно загружен и обработан.');
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

        return view('upload', compact('relationships', 'terms'));
    }
}

