<?php

use App\Http\Controllers\FreezerInventoryController;

Route::post('/freezer/add', [FreezerInventoryController::class, 'add'])->name('freezer.add');
Route::post('/freezer/remove', [FreezerInventoryController::class, 'remove'])->name('freezer.remove');
Route::post('/freezer/check', [FreezerInventoryController::class, 'check'])->name('freezer.check');
