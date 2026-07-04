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

it('hides root fields from serialized output without allowed fields', function () {
    $model = QueryBuilder::for(TestModel::class)
        ->hideFields('password', 'remember_token')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized)->not->toHaveKey('password');
    expect($serialized)->not->toHaveKey('remember_token');
    expect($serialized)->toHaveKey('name');
});

it('hides related model fields from serialized output', function () {
    $request = new Request([
        'include' => ['relatedModels'],
    ]);

    $model = QueryBuilder::for(TestModel::class, $request)
        ->allowedIncludes('relatedModels')
        ->hideFields('relatedModels.guard_name')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized['related_models'][0])->not->toHaveKey('guard_name');
    expect($serialized['related_models'][0])->toHaveKey('name');
});

it('hides wildcard fields on root and loaded relations', function () {
    $request = new Request([
        'include' => ['relatedModels'],
    ]);

    $model = QueryBuilder::for(TestModel::class, $request)
        ->allowedIncludes('relatedModels')
        ->hideFields('*.created_at')
        ->findOrFail($this->root->id);

    $serialized = $model->toArray();

    expect($serialized)->not->toHaveKey('created_at');
    expect($serialized['related_models'][0])->not->toHaveKey('created_at');
});
