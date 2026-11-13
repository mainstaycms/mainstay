<?php

use Illuminate\Support\Facades\Route;
use Mainstay\Ai\ConnectorController;

Route::get('/', [ConnectorController::class, 'index'])->name('index');
Route::post('/', [ConnectorController::class, 'defaults'])->name('defaults');
Route::get('models', [ConnectorController::class, 'models'])->name('models');
Route::post('models/refresh', [ConnectorController::class, 'refreshModels'])->name('models.refresh');
Route::post('{provider}', [ConnectorController::class, 'save'])->name('save');
Route::post('{provider}/forget', [ConnectorController::class, 'forget'])->name('forget');
Route::post('{provider}/test', [ConnectorController::class, 'test'])->name('test');
