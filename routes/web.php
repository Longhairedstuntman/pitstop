<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminUserController;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('welcome');
})->name('home');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::middleware(['auth', 'verified', 'ops'])->group(function () {
        Route::get('/ops/tickets', [TicketController::class, 'opsIndex'])->name('ops.tickets.index');
        Route::get('/ops/tickets/{ticket}', [TicketController::class, 'opsShow'])->name('ops.tickets.show');
        Route::post('/ops/tickets/{ticket}/status', [TicketController::class, 'opsUpdateStatus'])->name('ops.tickets.status');
        Route::post('/ops/tickets/{ticket}/reply', [TicketController::class, 'storeReply'])->name('ops.tickets.reply');
    });

    Route::middleware(['auth', 'verified', 'admin'])->group(function () {
        Route::delete('/ops/tickets/{ticket}', [TicketController::class, 'opsDestroy'])->name('ops.tickets.destroy');
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::patch('/admin/users/{user}/password', [AdminUserController::class, 'updatePassword'])->name('admin.users.password');
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
    });

    Route::get('/my-tickets', [\App\Http\Controllers\MyTicketsController::class, 'index'])->name('my_tickets.index');
    Route::get('/my-tickets/{ticket}', [\App\Http\Controllers\MyTicketsController::class, 'show'])
    ->name('my_tickets.show');
    Route::post('/my-tickets/{ticket}/reply', [\App\Http\Controllers\MyTicketsController::class, 'storeReply'])
        ->name('my_tickets.reply');

    Route::get('/tickets/create', [\App\Http\Controllers\TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [\App\Http\Controllers\TicketController::class, 'store'])->name('tickets.store');

    // Attachments are on a private disk; this route authorises each download.
    Route::get('/attachments/{attachment}', [\App\Http\Controllers\TicketAttachmentController::class, 'show'])
        ->name('ticket_attachments.show');
});

require __DIR__.'/auth.php';
