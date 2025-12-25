<?php
use Illuminate\Support\Facades\Route;
Routes::get('/', function (){
return view('welcome');
});
route::get('/day2', function (){
return 'Day2 Route is working';
});
