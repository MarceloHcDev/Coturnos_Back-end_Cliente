<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agendador de tarefas em segundo plano para cancelar pedidos pendentes expirados
Schedule::command('orders:cancel-expired --minutes=30')
    ->everyTenMinutes()
    ->withoutOverlapping();
