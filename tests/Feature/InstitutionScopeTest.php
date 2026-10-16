<?php

use App\Models\Institution;
use App\Models\User;
use App\Support\InstitutionScope;

it('gives administrators oversight across all institutions without requiring pivots', function () {
    $bst = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    $mif = Institution::create([
        'code' => 'MIF',
        'name' => 'Mamburao Integrated Farm',
        'is_active' => true,
    ]);

    $admin = User::factory()->create([
        'role' => 'administrator',
        'account_status' => 'active',
    ]);

    expect(InstitutionScope::idsFor($admin)->map(fn ($id) => (int) $id)->all())
        ->toBe([$bst->id, $mif->id]);
});

it('limits registrars to their assigned institutions', function () {
    $assigned = Institution::create([
        'code' => 'BST',
        'name' => 'Balli School of Technology',
        'is_active' => true,
    ]);

    Institution::create([
        'code' => 'MIF',
        'name' => 'Mamburao Integrated Farm',
        'is_active' => true,
    ]);

    $registrar = User::factory()->create([
        'role' => 'registrar',
        'account_status' => 'active',
    ]);
    $registrar->institutions()->attach($assigned->id, ['is_primary' => true]);

    expect(InstitutionScope::idsFor($registrar)->map(fn ($id) => (int) $id)->all())
        ->toBe([$assigned->id]);
});
