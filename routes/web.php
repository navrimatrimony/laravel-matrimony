<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/day2', function(){
return 'Day 2 is working!';
});

Route::get('/practice1', function(){
return 'Practice 1 OK';
});
