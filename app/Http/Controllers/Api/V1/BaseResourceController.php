<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class BaseResourceController extends Controller
{
    abstract protected function modelClass(): string;

    protected function routeKeyColumn(): string
    {
        return 'id';
    }

    protected function fillableFields(): array
    {
        return (new ($this->modelClass()))->getFillable();
    }

    protected function filterableColumns(): array
    {
        $cols = $this->fillableFields();
        $cols[] = 'id';
        $key = $this->routeKeyColumn();
        if ($key !== 'id' && ! in_array($key, $cols, true)) {
            $cols[] = $key;
        }
        return $cols;
    }

    public function index(Request $request)
    {
        $query = ($this->modelClass())::query();
        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }
        if ($q = $request->input('q')) {
            $searchCol = $this->routeKeyColumn() !== 'id'
                ? $this->routeKeyColumn()
                : ($this->fillableFields()[0] ?? 'id');
            $query->where($searchCol, 'like', "%{$q}%");
        }
        $perPage = min((int) $request->input('per_page', 25), 200);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model = ($this->modelClass())::create($request->validate($rules));
        return response()->json($model, 201);
    }

    public function show(string $id)
    {
        $model = ($this->modelClass())::where($this->routeKeyColumn(), $id)->firstOrFail();
        return response()->json($model);
    }

    public function update(Request $request, string $id)
    {
        $model = ($this->modelClass())::where($this->routeKeyColumn(), $id)->firstOrFail();
        $rules = array_fill_keys($this->fillableFields(), 'nullable');
        $model->update($request->validate($rules));
        return response()->json($model);
    }

    public function destroy(string $id)
    {
        ($this->modelClass())::where($this->routeKeyColumn(), $id)->firstOrFail()->delete();
        return response()->json(null, 204);
    }
}
