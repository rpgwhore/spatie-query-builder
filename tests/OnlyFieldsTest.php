<?php

use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\Tests\TestClasses\Models\RelatedModel;
use Spatie\QueryBuilder\Tests\TestClasses\Models\TestModel;

beforeEach(function () {
    $this->root = TestModel::factory()->create([
        'name' => 'Root User',
        'password' => 'secret',
        'remember_token' => 'token',
    ]);

    RelatedModel::create([
        'test_model_id' => $this->root->id,
        'name' => 'Related Role',
        'guard_name' => 'api',
    ]);
});

it('shows only configured root fields without request field selection', function () {
    $model = QueryBuilder::for(TestModel::class)
        ->onlyFields('id', 'name')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized)->toHaveKeys(['id', 'name']);
    expect($serialized)->not->toHaveKey('password');
    expect($serialized)->not->toHaveKey('remember_token');
});

it('intersects requested fields with only fields', function () {
    $request = new Request([
        'fields' => ['test_models' => 'id,name,password'],
    ]);

    $model = QueryBuilder::for(TestModel::class, $request)
        ->allowedFields('id', 'name', 'password')
        ->onlyFields('id', 'name')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized)->toHaveKeys(['id', 'name']);
    expect($serialized)->not->toHaveKey('password');
});

it('supports relation-path only fields for loaded includes', function () {
    $request = new Request([
        'include' => ['relatedModels'],
    ]);

    $model = QueryBuilder::for(TestModel::class, $request)
        ->allowedIncludes('relatedModels')
        ->onlyFields('id', 'name', 'relatedModels.id', 'relatedModels.name')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized)->toHaveKeys(['id', 'name']);
    expect($serialized['related_models'][0])->toHaveKeys(['id', 'name']);
    expect($serialized['related_models'][0])->not->toHaveKey('guard_name');
});

it('supports wildcard only fields across root and loaded relations', function () {
    $request = new Request([
        'include' => ['relatedModels'],
    ]);

    $model = QueryBuilder::for(TestModel::class, $request)
        ->allowedIncludes('relatedModels')
        ->onlyFields('*.id', '*.name')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized)->toHaveKeys(['id', 'name']);
    expect($serialized['related_models'][0])->toHaveKeys(['id', 'name']);
    expect($serialized['related_models'][0])->not->toHaveKey('guard_name');
});
