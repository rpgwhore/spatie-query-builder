<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\Exceptions\InvalidFieldQuery;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\Tests\TestClasses\Models\MorphModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\RelatedModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\TestModel;

beforeEach(function () {
    $this->root = TestModel::factory()->create([
        'name' => 'Root User',
        'password' => 'secret',
        'remember_token' => 'token',
    ]);

    $this->belongsToRelated = RelatedModel::create([
        'test_model_id' => 0,
        'name' => 'BelongsTo Role',
        'guard_name' => 'web',
    ]);

    $this->root->update([
        'related_model_id' => $this->belongsToRelated->id,
    ]);

    $this->hasManyRelated = RelatedModel::create([
        'test_model_id' => $this->root->id,
        'name' => 'HasMany Role',
        'guard_name' => 'api',
    ]);

    $this->hasManyRelated->nestedRelatedModels()->create([
        'name' => 'Nested Permission',
    ]);

    $this->root->morphModels()->create([
        'name' => 'Morph Child',
    ]);

    $this->root->relatedThroughPivotModels()->create([
        'id' => $this->root->id + 100,
        'name' => 'Pivot Role',
    ]);
});

it('treats wildcard excluded root fields as invalid requested fields', function () {
    $this->expectException(InvalidFieldQuery::class);

    $request = new Request([
        'fields' => ['test_models' => 'id,name,created_at'],
    ]);

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'name', 'created_at')
        ->exceptFields('*.created_at');
});

it('merges wildcard and relation-path exclusions for validation', function () {
    $this->expectException(InvalidFieldQuery::class);

    $request = new Request([
        'fields' => ['test_models' => 'id,name,password,created_at'],
    ]);

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'name', 'password', 'created_at')
        ->exceptFields('*.created_at', 'password');
});

it('treats excluded included relationship fields as invalid', function () {
    $this->expectException(InvalidFieldQuery::class);

    $request = new Request([
        'fields' => [
            'test_models' => 'id',
            'related_models' => 'name,guard_name',
        ],
        'include' => ['relatedModels'],
    ]);

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'related_models.name', 'related_models.guard_name')
        ->allowedIncludes('relatedModels')
        ->exceptFields('relatedModels.guard_name');
});

it('treats excluded nested relationship fields as invalid', function () {
    $this->expectException(InvalidFieldQuery::class);

    $request = new Request([
        'fields' => [
            'test_models' => 'name',
            'nested_related_models' => 'id,name',
        ],
        'include' => ['relatedModels.nestedRelatedModels'],
    ]);

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('name', 'nested_related_models.id', 'nested_related_models.name')
        ->allowedIncludes('relatedModels.nestedRelatedModels')
        ->exceptFields('*.id');
});

it('excludes server-side fields from generated relationship select statements', function () {
    $request = new Request([
        'fields' => [
            'test_models' => 'id,name',
            'related_models' => 'name',
        ],
        'include' => ['relatedModels'],
    ]);

    DB::enableQueryLog();

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'name', 'related_models.name', 'related_models.guard_name')
        ->allowedIncludes('relatedModels')
        ->exceptFields('relatedModels.guard_name')
        ->first()
        ?->relatedModels;

    $this->assertQueryLogContains('related_models');
    $this->assertQueryLogContains('name');
    $this->assertQueryLogDoesntContain('guard_name');
});

it('preserves required hasMany keys even when excluded', function () {
    $request = new Request([
        'fields' => [
            'test_models' => 'name',
            'related_models' => 'name',
        ],
        'include' => ['relatedModels'],
    ]);

    DB::enableQueryLog();

    $result = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('name', 'related_models.name')
        ->allowedIncludes('relatedModels')
        ->exceptFields('*.id', 'relatedModels.test_model_id')
        ->findOrFail($this->root->id);

    expect($result->relatedModels)->toHaveCount(1);

    $this->assertQueryLogContains('test_model_id');
});

it('preserves required belongsTo keys even when excluded', function () {
    $request = new Request([
        'fields' => [
            'test_models' => 'name',
            'related_models' => 'name',
        ],
        'include' => ['relatedModel'],
    ]);

    DB::enableQueryLog();

    $result = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('name', 'related_models.name')
        ->allowedIncludes('relatedModel')
        ->exceptFields('*.id', 'related_model_id')
        ->findOrFail($this->root->id);

    expect($result->relatedModel)->not()->toBeNull();
    expect($result->relatedModel->name)->toBe('BelongsTo Role');

    $this->assertQueryLogContains('related_model_id');
});

it('preserves required belongsToMany keys even when excluded', function () {
    $request = new Request([
        'fields' => [
            'test_models' => 'name',
            'related_through_pivot_models' => 'name',
        ],
        'include' => ['relatedThroughPivotModels'],
    ]);

    DB::enableQueryLog();

    $result = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('name', 'related_through_pivot_models.name')
        ->allowedIncludes('relatedThroughPivotModels')
        ->exceptFields('*.id')
        ->findOrFail($this->root->id);

    expect($result->relatedThroughPivotModels)->toHaveCount(1);

    $this->assertQueryLogContains('pivot_test_model_id');
});

it('preserves required morph keys even when excluded', function () {
    $request = new Request([
        'fields' => [
            'test_models' => 'name',
            'morph_models' => 'name',
        ],
        'include' => ['morphModels'],
    ]);

    DB::enableQueryLog();

    $result = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('name', 'morph_models.name')
        ->allowedIncludes('morphModels')
        ->exceptFields('*.id', 'morphModels.parent_id', 'morphModels.parent_type')
        ->findOrFail($this->root->id);

    expect($result->morphModels)->toHaveCount(1);

    $this->assertQueryLogContains('parent_id');
    $this->assertQueryLogContains('parent_type');
});

it('preserves keys for nested includes when exclusions are active', function () {
    $request = new Request([
        'fields' => [
            'test_models' => 'name',
            'related_models' => 'name',
            'nested_related_models' => 'name',
        ],
        'include' => ['relatedModels.nestedRelatedModels'],
    ]);

    $result = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('name', 'related_models.name', 'nested_related_models.name')
        ->allowedIncludes('relatedModels.nestedRelatedModels')
        ->exceptFields('*.id', 'relatedModels.test_model_id', 'relatedModels.nestedRelatedModels.related_model_id')
        ->findOrFail($this->root->id);

    expect($result->relatedModels)->toHaveCount(1);
    expect($result->relatedModels->first()->nestedRelatedModels)->toHaveCount(1);
});

it('remains fully backwards compatible when exceptFields is not used', function () {
    $request = new Request([
        'fields' => ['test_models' => 'id,name,created_at'],
    ]);

    $query = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'name', 'created_at')
        ->toSql();

    $expected = TestModel::query()
        ->select('test_models.id', 'test_models.name', 'test_models.created_at')
        ->toSql();

    expect($query)->toEqual($expected);
});

it('supports wildcard-prefixed field syntax', function () {
    $this->expectException(InvalidFieldQuery::class);

    $request = new Request([
        'fields' => ['test_models' => 'id,name,created_at'],
    ]);

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'name', 'created_at')
        ->exceptFields('*.created_at');
});

it('supports relation-path field syntax', function () {
    $this->expectException(InvalidFieldQuery::class);

    $request = new Request([
        'fields' => [
            'test_models' => 'id',
            'related_models' => 'name,guard_name',
        ],
        'include' => ['relatedModels'],
    ]);

    QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'related_models.name', 'related_models.guard_name')
        ->allowedIncludes('relatedModels')
        ->exceptFields('relatedModels.guard_name');
});
