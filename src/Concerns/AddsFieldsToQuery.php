<?php

namespace Spatie\QueryBuilder\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\Exceptions\InvalidFieldQuery;
use Spatie\QueryBuilder\Exceptions\UnknownIncludedFieldsQuery;

trait AddsFieldsToQuery
{
    protected ?Collection $allowedFields = null;

    protected Collection $exceptedFieldsWildcard;

    protected Collection $exceptedFieldsPerModel;

    protected bool $hasExceptedFields = false;

    public function allowedFields(string ...$fields): static
    {
        $this->allowedFields = collect($fields)
            ->map(function (string $fieldName) {
                return $this->prependField($fieldName);
            })
            ->reject(fn (string $fieldName) => $this->isFieldExcluded($fieldName))
            ->values();

        $this->ensureAllFieldsExist();

        $this->addRequestedModelFieldsToQuery();

        return $this;
    }

    public function exceptFields(string ...$fields): static
    {
        $this->exceptedFieldsWildcard = collect();
        $this->exceptedFieldsPerModel = collect();

        collect($fields)->each(fn (string $field) => $this->registerExcludedFieldSpecifier($field));

        $this->exceptedFieldsWildcard = $this->exceptedFieldsWildcard->unique()->values();
        $this->exceptedFieldsPerModel = $this->exceptedFieldsPerModel
            ->map(fn (Collection $fields) => $fields->unique()->values());

        $this->hasExceptedFields = $this->exceptedFieldsWildcard->isNotEmpty() || $this->exceptedFieldsPerModel->isNotEmpty();

        if ($this->allowedFields instanceof Collection) {
            $this->allowedFields = $this->allowedFields
                ->reject(fn (string $fieldName) => $this->isFieldExcluded($fieldName))
                ->values();

            $this->ensureAllFieldsExist();
            $this->addRequestedModelFieldsToQuery();

            if (property_exists($this, 'allowedIncludes') && $this->allowedIncludes instanceof Collection) {
                $this->addIncludesToQuery($this->filterNonExistingIncludes($this->request->includes()));
            }
        }

        return $this;
    }

    protected function registerExcludedFieldSpecifier(string $specifier): void
    {
        $specifier = trim($specifier);

        if ($specifier === '') {
            return;
        }

        if (! Str::contains($specifier, '.')) {
            $this->addModelExclusion($this->getModel()::class, $specifier);

            return;
        }

        $path = Str::beforeLast($specifier, '.');
        $column = Str::afterLast($specifier, '.');

        if ($path === '*') {
            $this->exceptedFieldsWildcard->push($column);

            return;
        }

        $modelClass = is_subclass_of($path, Model::class)
            ? $path
            : $this->resolveModelClassFromPath($path);

        if (! $modelClass) {
            return;
        }

        $this->addModelExclusion($modelClass, $column);
    }

    protected function addModelExclusion(string $modelClass, string $column): void
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return;
        }

        $column = trim($column);

        if ($column === '') {
            return;
        }

        /** @var Collection<int, string> $existing */
        $existing = $this->exceptedFieldsPerModel->get($modelClass, collect());

        $this->exceptedFieldsPerModel->put($modelClass, $existing->push(Str::afterLast($column, '.')));
    }

    protected function addRequestedModelFieldsToQuery(): void
    {
        $modelTableName = $this->getModel()->getTable();

        $fields = $this->request->fields();

        if (! $fields->isEmpty() && config('query-builder.convert_field_names_to_snake_case', false)) {
            $fields = $fields->mapWithKeys(fn ($fields, $table) => [$table => collect($fields)->map(fn ($field) => Str::snake($field))->toArray()]);
        }

        $strategy = config('query-builder.convert_relation_table_name_strategy');

        if ($strategy === 'camelCase') {
            $modelFields = $fields->has(Str::camel($modelTableName)) ? $fields->get(Str::camel($modelTableName)) : $fields->get('_');
        } else {
            $modelFields = $fields->has($modelTableName) ? $fields->get($modelTableName) : $fields->get('_');
        }

        if (empty($modelFields)) {
            return;
        }

        $prependedFields = collect($this->prependFieldsWithTableName($modelFields, $modelTableName))
            ->reject(fn (string $fieldName) => $this->isFieldExcluded($fieldName))
            ->values();

        if ($this->hasExceptedFields) {
            $prependedFields = $prependedFields
                ->merge($this->requiredRootFieldsForRequestedIncludes())
                ->unique()
                ->values();
        }

        $this->select($prependedFields->all());
    }

    public function getRequestedFieldsForRelatedTable(string $relation, ?string $tableName = null, ?Relation $relationInstance = null): array
    {
        $possibleRelatedNames = [
            config('query-builder.convert_relation_names_to_snake_case_plural', true)
                ? Str::plural(Str::snake($relation))
                : $relation,
        ];

        $strategy = config('query-builder.convert_relation_table_name_strategy');

        if ($strategy === 'snake_case' && $tableName) {
            $possibleRelatedNames[] = Str::snake($tableName);
        } elseif ($strategy === 'camelCase' && $tableName) {
            $possibleRelatedNames[] = Str::camel($tableName);
        } elseif ($strategy === 'none') {
            $possibleRelatedNames[] = $tableName;
        }

        $possibleRelatedNames = array_filter($possibleRelatedNames);

        $fields = $this->request->fields()
            ->mapWithKeys(fn ($fields, $table) => [$table => collect($fields)->map(fn ($field) => config('query-builder.convert_field_names_to_snake_case', false) ? Str::snake($field) : $field)])
            ->filter(fn ($value, $table) => in_array($table, $possibleRelatedNames))
            ->first();

        if (! $fields) {
            return [];
        }

        $relatedModelClass = $this->resolveModelClassFromPath($relation);

        $fields = $fields
            ->reject(fn (string $fieldName) => $this->isFieldExcluded($fieldName, $relatedModelClass))
            ->values()
            ->toArray();

        if ($tableName !== null) {
            $fields = $this->prependFieldsWithTableName($fields, $tableName);
        }

        if ($this->hasExceptedFields && $relationInstance) {
            $fields = collect($fields)
                ->merge($this->requiredRelatedFieldsForRelation($relationInstance))
                ->unique()
                ->values()
                ->all();
        }

        if (! $this->allowedFields instanceof Collection) {
            throw new UnknownIncludedFieldsQuery($fields);
        }

        return $fields;
    }

    protected function ensureAllFieldsExist(): void
    {
        $modelTable = $this->getModel()->getTable();

        $requestedFields = $this->request->fields()
            ->map(function ($fields, $model) use ($modelTable) {
                $tableName = $model;

                return $this->prependFieldsWithTableName($fields, $model === '_' ? $modelTable : $tableName);
            })
            ->flatten()
            ->unique();

        $unknownFields = $requestedFields->diff($this->allowedFields);

        if ($unknownFields->isNotEmpty()) {
            throw InvalidFieldQuery::fieldsNotAllowed($unknownFields, $this->allowedFields);
        }
    }

    protected function prependFieldsWithTableName(array $fields, string $tableName): array
    {
        return array_map(function ($field) use ($tableName) {
            return $this->prependField($field, $tableName);
        }, $fields);
    }

    protected function prependField(string $field, ?string $table = null): string
    {
        if (! $table) {
            $table = $this->getModel()->getTable();
        }

        if (Str::contains($field, '.')) {
            return $field;
        }

        return "{$table}.{$field}";
    }

    protected function isFieldExcluded(string $fieldName, ?string $resolvedModelClass = null): bool
    {
        if (! $this->hasExceptedFields) {
            return false;
        }

        $columnName = Str::afterLast($fieldName, '.');

        if ($this->exceptedFieldsWildcard->contains($columnName)) {
            return true;
        }

        $modelClass = $resolvedModelClass ?? $this->resolveModelClassForField($fieldName);

        if (! $modelClass) {
            return false;
        }

        /** @var Collection<int, string>|null $modelExclusions */
        $modelExclusions = $this->exceptedFieldsPerModel->get($modelClass);

        if (! $modelExclusions instanceof Collection) {
            return false;
        }

        return $modelExclusions->contains($columnName);
    }

    protected function resolveModelClassForField(string $fieldName): ?string
    {
        if (! Str::contains($fieldName, '.')) {
            return $this->getModel()::class;
        }

        $fieldPath = Str::beforeLast($fieldName, '.');
        $modelTable = $this->getModel()->getTable();

        if ($fieldPath === $modelTable || $fieldPath === Str::camel($modelTable) || $fieldPath === '_') {
            return $this->getModel()::class;
        }

        return $this->resolveModelClassFromPath($fieldPath);
    }

    protected function resolveModelClassFromPath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('.', $path)));

        if ($segments === []) {
            return $this->getModel()::class;
        }

        $currentModel = $this->getModel();

        foreach ($segments as $segment) {
            $relationName = $this->resolveRelationName($currentModel, $segment);

            if (! $relationName) {
                return null;
            }

            try {
                $currentModel = $currentModel->{$relationName}()->getRelated();
            } catch (\Throwable) {
                return null;
            }
        }

        return $currentModel::class;
    }

    protected function resolveRelationName(Model $model, string $segment): ?string
    {
        $candidates = collect([
            $segment,
            Str::camel($segment),
            Str::snake($segment),
            Str::plural($segment),
            Str::plural(Str::camel($segment)),
            Str::plural(Str::snake($segment)),
            Str::singular($segment),
            Str::singular(Str::camel($segment)),
            Str::singular(Str::snake($segment)),
        ])->unique()->values();

        foreach ($candidates as $candidate) {
            if (! method_exists($model, $candidate)) {
                continue;
            }

            try {
                $relation = $model->{$candidate}();

                if ($relation instanceof Relation) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        foreach (get_class_methods($model) as $method) {
            try {
                $reflectionMethod = new \ReflectionMethod($model, $method);

                if ($reflectionMethod->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $relation = $model->{$method}();

                if (! $relation instanceof Relation) {
                    continue;
                }

                if ($relation->getRelated()->getTable() === $segment) {
                    return $method;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    protected function requiredRootFieldsForRequestedIncludes(): Collection
    {
        $requestedIncludes = $this->request->includes();

        if ($requestedIncludes->isEmpty()) {
            return collect();
        }

        return $requestedIncludes
            ->map(fn (string $include) => Str::before($include, '.'))
            ->unique()
            ->flatMap(function (string $relationName): array {
                try {
                    $relation = $this->getModel()->{$relationName}();

                    if (! $relation instanceof Relation) {
                        return [];
                    }

                    return $this->requiredRootFieldsForRelation($relation);
                } catch (\Throwable) {
                    return [];
                }
            })
            ->map(fn (string $column) => $this->prependField($column))
            ->unique()
            ->values();
    }

    protected function requiredRootFieldsForRelation(Relation $relation): array
    {
        if ($relation instanceof BelongsTo) {
            return [$relation->getForeignKeyName()];
        }

        if ($relation instanceof MorphTo) {
            return [$relation->getForeignKeyName(), $relation->getMorphType()];
        }

        if ($relation instanceof BelongsToMany) {
            return [$relation->getParentKeyName()];
        }

        if ($relation instanceof HasOne || $relation instanceof HasMany || $relation instanceof MorphOne || $relation instanceof MorphMany || $relation instanceof HasManyThrough) {
            return [$relation->getLocalKeyName()];
        }

        return [$this->getModel()->getKeyName()];
    }

    protected function requiredRelatedFieldsForRelation(Relation $relation): Collection
    {
        $requiredFields = [];

        $relatedTable = $relation->getRelated()->getTable();

        if ($relation instanceof BelongsTo) {
            $requiredFields[] = $relation->getOwnerKeyName();
        }

        if ($relation instanceof BelongsToMany) {
            $requiredFields[] = $relation->getRelatedKeyName();
        }

        if ($relation instanceof HasOne || $relation instanceof HasMany) {
            $requiredFields[] = $relation->getForeignKeyName();
            $requiredFields[] = $relation->getRelated()->getKeyName();
        }

        if ($relation instanceof MorphOne || $relation instanceof MorphMany) {
            $requiredFields[] = $relation->getForeignKeyName();
            $requiredFields[] = $relation->getMorphType();
            $requiredFields[] = $relation->getRelated()->getKeyName();
        }

        if ($relation instanceof HasManyThrough) {
            $requiredFields[] = $relation->getRelated()->getKeyName();
        }

        if ($requiredFields === []) {
            $requiredFields[] = $relation->getRelated()->getKeyName();
        }

        return collect($requiredFields)
            ->filter(fn (?string $column) => filled($column))
            ->map(fn (string $column) => $this->prependField($column, $relatedTable))
            ->unique()
            ->values();
    }
}
