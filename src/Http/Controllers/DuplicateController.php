<?php

namespace Jackabox\DuplicateField\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

class DuplicateController extends Controller
{
    /**
     * Duplicate a nova field and all of the relations defined.
     * @param Request $request
     * @return array
     */
    public function duplicate(Request $request)
    {
        $modelClass = $request->post('model_class');
        $modelKeyName = $request->post('model_key_name');
        $modelKeyValue = $request->post('model_key_value');
        $resource = $request->post('resource');

        $except = Arr::wrap($request->post('except'));
        $override = Arr::wrap($request->post('override'));
        $relations = Arr::wrap($request->post('relations'));
        $relationsExcept = Arr::wrap($request->get('relations_except'));
        $relationsOverride = Arr::wrap($request->post('relations_override'));

        /** @var Model|null $model */
        $model = $modelClass::where($modelKeyName, $modelKeyValue)->first();

        if (!$model) {
            return [
                'status' => 404,
                'message' => 'No model found.',
                'destination' => '/' . trim(config('nova.path'), '/') . '/resources/' . $resource . '/'
            ];
        }

        $newModel = $this->replicate($model, $except, $override, $relations, $relationsExcept, $relationsOverride);

        // return response and redirect.
        return [
            'status' => 200,
            'message' => 'Done',
            'destination' => '/' . trim(config('nova.path'), '/') . '/resources/' . $resource . '/' . $newModel->getKey()."/edit"
        ];
    }

    /**
     * @param Model $model
     * @param array $except
     * @param array $override
     * @param array $relations
     * @param array $relationsExcept
     * @param array $relationsOverride
     * @return Model
     */
    private function replicate(Model $model, array $except = [], array $override = [], array $relations = [], array $relationsExcept = [], array $relationsOverride = [])
    {
        $newModel = $model->replicate($except);

        foreach ($override as $field => $value) {
            $newModel->{$field} = $value;
        }

        $newModel->push();

        $this->replicateRelations($model, $newModel, $relations, $relationsExcept, $relationsOverride);

        return $newModel;
    }

    /**
     * todo: implement deep relations replication
     * @param Model $originalModel
     * @param Model $newModel
     * @param array $relations
     * @param array $except
     * @param array $override
     */
    private function replicateRelations(Model $originalModel, Model $newModel, array $relations = [], array $except = [], array $override = [])
    {
        // tested only with hasMany

        if (!count($relations)) {
            return;
        }

        $originalModel->load($relations);

        foreach ($originalModel->getRelations() as $relationName => $items) {
            $relation = $newModel->{$relationName}();
            $relationType = (new \ReflectionClass($relation))->getShortName();
            if ($relationType === 'BelongsToMany') {
                $withOrder = $items?->first()?->pivot && array_key_exists('order', $items?->first()?->pivot?->toArray());
                if ($withOrder) {
                    $relation->sync($items->mapWithKeys(fn ($item) => [$item->id => ['order' => $item->pivot->order]]));
                } else {
                    $relation->sync($items->pluck('id'));
                }
            } elseif ($relationType === 'HasMany') {
                $relationOverride = array_merge(
                    Arr::wrap(Arr::get($override, $relationName)),
                    [$relation->getForeignKeyName() => $newModel->getKey()]
                );
                foreach ($items as $item) {
                    $this->replicate($item, $except, $relationOverride);
                }
            }
        }
    }
}
